<?php

// This is a demo callback, you can do your stuff here e.g. do a webcall;
function filterCallback($data)
{
    echo 'The following changes happened to the prefix list for: '.$data['asnOrSet'].PHP_EOL;
    foreach ($data['added'] as $add) {
        echo '+'.$add.PHP_EOL;
    }
    foreach ($data['removed'] as $remove) {
        echo '-'.$remove.PHP_EOL;
    }
}
