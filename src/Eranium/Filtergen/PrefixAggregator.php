<?php
/**
 * Eranium Filtergen
 * @copyright Eranium B.V.
 * @license Mozilla Public License 2.0
 * @link https://github.com/eranium/filtergen
 */

declare(strict_types=1);

namespace Eranium\Filtergen;

/**
 * Radix trie node used internally by PrefixAggregator.
 *
 * Mirrors the sx_radix_node_t structure from bgpq4's sx_prefix.c.
 */
class TrieNode
{
    public string $addr;           // Binary address, host bits zeroed to $len
    public int $len;               // Prefix length
    public bool $isGlue;           // true = synthesised node, not in original data
    public bool $isAggregate = false;   // This node has aggregated descendants
    public bool $isAggregated = false;  // This node was absorbed into its parent aggregate
    public int $aggLow = 0;        // Minimum mask length in the aggregate range
    public int $aggHi = 0;         // Maximum mask length in the aggregate range
    public ?self $left = null;     // Child reached by bit $len = 0
    public ?self $right = null;    // Child reached by bit $len = 1

    public function __construct(string $addr, int $len, bool $isGlue)
    {
        $this->addr = $addr;
        $this->len = $len;
        $this->isGlue = $isGlue;
    }
}

/**
 * Aggregates (compresses) a list of CIDR prefixes using the same radix-trie
 * algorithm as bgpq4's sx_radix_node_aggregate().
 *
 * Output rule strings may include Arista-style ge/le qualifiers:
 *   "10.0.0.0/21"                    – plain prefix
 *   "10.0.0.0/21 ge 24 le 24"        – aggregated more-specifics (not the /21 itself)
 *   "10.0.0.0/23 le 24"              – /23 itself + all /24s within (aggLow == len+1)
 *
 * The three merge cases from the C source:
 *   Basic     – two sibling plain-real nodes → parent.aggLow = parent.aggHi = child.len
 *   Extended  – two sibling real+aggregate nodes with matching range
 *               → parent.aggLow = child.len, parent.aggHi = child.aggHi
 *   Cascading – two sibling glue-aggregate nodes with matching range
 *               → parent inherits child.aggLow / child.aggHi unchanged
 */
class PrefixAggregator
{
    /**
     * Aggregate a list of CIDR prefix strings.
     *
     * @param  array  $prefixes  e.g. ['10.0.0.0/24', '10.0.1.0/24']
     * @return array             Rule strings suitable for Arista prefix-lists
     */
    public static function aggregate(array $prefixes): array
    {
        if (empty($prefixes)) {
            return [];
        }

        // Parse, mask host bits, deduplicate
        $seen = [];
        $parsed = [];
        foreach ($prefixes as $prefix) {
            [$ip, $len] = explode('/', $prefix, 2);
            $len = (int) $len;
            $addr = self::maskAddress(inet_pton($ip), $len);
            $key = $addr.':'.$len;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $parsed[] = [$addr, $len];
            }
        }

        // Build radix trie
        $root = null;
        foreach ($parsed as [$addr, $len]) {
            self::insertNode($root, $addr, $len);
        }

        // Bottom-up aggregation
        if ($root !== null) {
            self::aggregateNode($root);
        }

        // Collect output rules
        $rules = [];
        if ($root !== null) {
            self::collectRules($root, $rules);
        }

        return $rules;
    }

    // -------------------------------------------------------------------------
    // Trie insertion (mirrors sx_radix_tree_insert)
    // -------------------------------------------------------------------------

    private static function insertNode(?TrieNode &$node, string $addr, int $len): void
    {
        if ($node === null) {
            $node = new TrieNode($addr, $len, false);
            return;
        }

        $eb = self::eqBits($addr, $len, $node->addr, $node->len);

        if ($eb === $len && $eb === $node->len) {
            // Exact match – mark as real (was glue)
            $node->isGlue = false;
            return;
        }

        if ($eb >= $node->len) {
            // Current node is a proper prefix of the new address – navigate deeper
            if (self::isBitSet($addr, $node->len)) {
                self::insertNode($node->right, $addr, $len);
            } else {
                self::insertNode($node->left, $addr, $len);
            }
            return;
        }

        if ($eb >= $len) {
            // New prefix is shorter – it becomes the parent of the current node
            $parent = new TrieNode($addr, $len, false);
            if (self::isBitSet($node->addr, $len)) {
                $parent->right = $node;
            } else {
                $parent->left = $node;
            }
            $node = $parent;
            return;
        }

        // Prefixes diverge at bit $eb – insert a glue node at that point
        $glue = new TrieNode(self::maskAddress($addr, $eb), $eb, true);
        $newNode = new TrieNode($addr, $len, false);

        if (self::isBitSet($node->addr, $eb)) {
            $glue->right = $node;
            $glue->left  = $newNode;
        } else {
            $glue->left  = $node;
            $glue->right = $newNode;
        }

        $node = $glue;
    }

    // -------------------------------------------------------------------------
    // Bottom-up aggregation (mirrors sx_radix_node_aggregate)
    // -------------------------------------------------------------------------

    private static function aggregateNode(TrieNode $node): void
    {
        // Process children first (post-order)
        if ($node->left !== null) {
            self::aggregateNode($node->left);
        }
        if ($node->right !== null) {
            self::aggregateNode($node->right);
        }

        $L = $node->left;
        $R = $node->right;

        // Both children must exist and be unabsorbed
        if ($L === null || $R === null || $L->isAggregated || $R->isAggregated) {
            return;
        }

        // Children must be exactly one level deeper (ensures they cover the full block)
        if ($L->len !== $node->len + 1 || $R->len !== $node->len + 1) {
            return;
        }

        $canMerge  = false;
        $newAggLow = 0;
        $newAggHi  = 0;

        if (!$L->isGlue && !$R->isGlue) {
            // --- Both children are real (non-glue) prefixes ---
            if (!$L->isAggregate && !$R->isAggregate) {
                // Basic merge: two plain real siblings
                $canMerge  = true;
                $newAggLow = $newAggHi = $L->len;
            } elseif (
                $L->isAggregate && $R->isAggregate
                && $L->aggLow === $R->aggLow
                && $L->aggHi === $R->aggHi
            ) {
                // Extended merge: two real+aggregate siblings with identical ranges.
                // The children's own prefix length is promoted as aggLow.
                $canMerge  = true;
                $newAggLow = $L->len;
                $newAggHi  = $L->aggHi;
            }
        } elseif (
            $L->isGlue && $R->isGlue
            && $L->isAggregate && $R->isAggregate
            && $L->aggLow === $R->aggLow
            && $L->aggHi === $R->aggHi
        ) {
            // Cascading merge: two glue aggregates with identical ranges.
            // The range propagates upward unchanged.
            $canMerge  = true;
            $newAggLow = $L->aggLow;
            $newAggHi  = $L->aggHi;
        }

        if ($canMerge) {
            $L->isAggregated = true;
            $R->isAggregated = true;
            $node->isAggregate = true;
            $node->aggLow = $newAggLow;
            $node->aggHi  = $newAggHi;
        }
    }

    // -------------------------------------------------------------------------
    // Rule collection
    // -------------------------------------------------------------------------

    /**
     * @param int $coveredUpTo  Maximum prefix length already covered by the nearest
     *                          ancestor aggregate rule (0 = nothing covered yet).
     *                          Real nodes at depth <= coveredUpTo are already matched
     *                          by that ancestor rule and must not be emitted again.
     *                          Nodes deeper than coveredUpTo are NOT covered and must
     *                          still be emitted — this is how "overflow" /24s inside a
     *                          "le /23" block get collected correctly.
     */
    private static function collectRules(TrieNode $node, array &$rules, int $coveredUpTo = 0): void
    {
        if ($node->isAggregated) {
            // This node is covered by an ancestor's aggregate rule.
            // Its own prefix is suppressed, but children deeper than coveredUpTo
            // may still need individual rules — recurse with the same coveredUpTo.
            if ($node->left !== null) {
                self::collectRules($node->left, $rules, $coveredUpTo);
            }
            if ($node->right !== null) {
                self::collectRules($node->right, $rules, $coveredUpTo);
            }
            return;
        }

        $prefix = inet_ntop($node->addr).'/'.$node->len;

        if (!$node->isGlue) {
            // Real prefix — only emit if not already covered by an ancestor aggregate rule
            if ($node->len > $coveredUpTo) {
                if (!$node->isAggregate) {
                    $rules[] = $prefix;
                } elseif ($node->aggLow === $node->len + 1) {
                    $rules[] = $prefix.' le '.$node->aggHi;
                } else {
                    $rules[] = $prefix;
                    $rules[] = $prefix.' ge '.$node->aggLow.' le '.$node->aggHi;
                }
            }
        } elseif ($node->isAggregate) {
            // Glue aggregate — only emit if not already covered
            if ($node->len > $coveredUpTo) {
                $rules[] = $prefix.' ge '.$node->aggLow.' le '.$node->aggHi;
            }
        }
        // Pure glue (non-aggregate): no output

        // Children at depth <= aggHi are covered by this node's rule(s); deeper ones are not
        $childCoveredUpTo = $node->isAggregate ? max($coveredUpTo, $node->aggHi) : $coveredUpTo;

        if ($node->left !== null) {
            self::collectRules($node->left, $rules, $childCoveredUpTo);
        }
        if ($node->right !== null) {
            self::collectRules($node->right, $rules, $childCoveredUpTo);
        }
    }

    // -------------------------------------------------------------------------
    // Bit-manipulation helpers
    // -------------------------------------------------------------------------

    /**
     * Number of equal leading bits between two prefixes, bounded by min(len1, len2).
     */
    private static function eqBits(string $addr1, int $len1, string $addr2, int $len2): int
    {
        $maxBits = min($len1, $len2);
        $total   = 0;
        $bytes   = strlen($addr1);
        for ($i = 0; $i < $bytes; $i++) {
            $xor = ord($addr1[$i]) ^ ord($addr2[$i]);
            if ($xor === 0) {
                $total += 8;
                if ($total >= $maxBits) {
                    return $maxBits;
                }
            } else {
                // Find the highest set bit (= first difference, reading MSB-first)
                for ($bit = 7; $bit >= 0; $bit--) {
                    if ($xor & (1 << $bit)) {
                        return min($total + (7 - $bit), $maxBits);
                    }
                }
            }
        }
        return min($total, $maxBits);
    }

    /**
     * True if bit $bit (0 = MSB) is set in the binary address string.
     */
    private static function isBitSet(string $addr, int $bit): bool
    {
        $byte      = intdiv($bit, 8);
        $bitInByte = 7 - ($bit % 8);
        return (ord($addr[$byte]) & (1 << $bitInByte)) !== 0;
    }

    /**
     * Return $addr with all bits beyond position $len zeroed.
     */
    private static function maskAddress(string $addr, int $len): string
    {
        $bytes  = strlen($addr);
        $result = '';
        for ($i = 0; $i < $bytes; $i++) {
            $keep = max(0, min(8, $len - $i * 8));
            if ($keep === 8) {
                $result .= $addr[$i];
            } elseif ($keep === 0) {
                $result .= "\x00";
            } else {
                $mask   = 0xFF & (0xFF << (8 - $keep));
                $result .= chr(ord($addr[$i]) & $mask);
            }
        }
        return $result;
    }
}
