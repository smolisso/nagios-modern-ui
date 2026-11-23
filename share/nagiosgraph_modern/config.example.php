<?php

return [
    // Choose 'nagiosgraph' or 'pnp4nagios'.
    'backend' => 'nagiosgraph',

    // Empty: automatically search standard Nagiosgraph locations.
    // For PNP4Nagios, set this to its perfdata directory (RRDPATH).
    'rrd_root' => '',

    // Empty: automatically locate rrdtool; otherwise use an absolute path.
    'rrdtool' => '',

    // Used to filter Nagiosgraph's host list when readable.
    'status_file' => '/usr/local/nagios/var/status.dat',
];
