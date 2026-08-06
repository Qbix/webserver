<?php
// Flushes early then keeps working: the client must still get the whole body.
echo "part1";
flush();
usleep(50000);
echo "-part2";
