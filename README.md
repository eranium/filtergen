# Filtergen
Interact with an IRRd server and generate prefix filters. Written in native PHP for direct implementation in an API/backend.

## Introduction
In our backends we currently use the popular bgpq4 for generating prefix filters. As we would like to further integrate this in our backends (natively), we decided to build this in PHP. There is no IRRd client to do this, so this is included as well.

## Examples
The project includes a few examples, they are explained below.

#### Client
IRRDClient allows you to talk to IRRd servers like:
```php
$newClient->command('!v')->command('!jARIN')->command('!sRIPE,RPKI')->command('!gAS65000')->read();
```
This would output an associative array with commands as keys and the output as values:
```
Array
(
    [!v] => IRRd -- version 4.4.4
    [!jARIN] => ARIN:Y:0-8205778
    [!sRIPE,RPKI] => 
    [!gAS65000] => 1.2.3.0/24 3.2.1.0/24
)
```

#### Filtergen
You can call Filtergen using the CLI with `php filtergen.php AS65000 RIPE,RPKI`, by default it outputs an array with all data and a prefix list in Arista format. The array of data includes last sync status of the used IRR database, which can be very helpful. Prefixes are sorted naturally using natsort().
```
Array
(
    [prefixes] => Array
        (
            [0] => 1.2.3.0/24
            [1] => 1.2.4.0/24
            [2] => 1.2.5.0/24
            [3] => 1.2.6.0/24
        )

    [sources] => Array
        (
            [0] => ARIN
        )

    [updated] => Array
        (
            [ARIN] => 2025-12-29T15:18:21.471908+00:00
        )

)
string(673) "seq 1 permit 1.2.3.0/24
seq 2 permit 1.2.4.0/24
seq 3 permit 1.2.5.0/24
seq 4 permit 1.2.6.0/24
"
```

#### API
There is a very simple API which can provide prefix lists to your switch, callable like: `api.php?set=AS65000&sources=RIPE,RPKI&type=4`, change the ASN with AS-SET to query recursively. Or swap 4 with 6 for IPv6. It should output like this, for direct use in your switch:
```
seq 1 permit 1.2.3.0/24
seq 2 permit 1.2.4.0/24
seq 3 permit 1.2.5.0/24
seq 4 permit 1.2.6.0/24
```

#### Snapshots
Filtergen supports storing snapshots of prefixes locally. Using these snapshots you are able to do callbacks on changes (e.g. added or removed prefixes). Rename `callback.dist.php` to `callback.php` to enable the callback feature. This callable function can be adjusted to your liking. Once changes are detected (when the API is queried) it will do a callback. Snapshots are stored in the `snapshots` folder in JSON format.

## Notes
Supported sources: `NTTCOM,INTERNAL,LACNIC,RADB,RIPE,RIPE-NONAUTH,ALTDB,BELL,LEVEL3,APNIC,JPIRR,ARIN,BBOI,TC,AFRINIC,IDNIC,RPKI,REGISTROBR,CANARIE`

If you're going to play with this, it's recommended to read the IRRd docs here: https://irrd.readthedocs.io/en/stable/users/queries/whois/

## TODO
Right now the code does not do aggregation of prefixes (-A argument of bgpq4), we plan to implement this soon as it's very important, especially during the RAM crisis :)
This project should be ready for Composer too.

## License
Mozilla Public License Version 2.0
