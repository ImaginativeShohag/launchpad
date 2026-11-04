<?php

/**
 * Every way a JSON field can have the wrong shape for a string field. Each must produce a 422, never a 500
 * (a JSON array or object cast to string raises "Array to string conversion").
 */
dataset('wrong-shaped values', [
    'array' => [['x']],
    'empty array' => [[]],
    'object' => [['a' => 'b']],
    'nested array' => [[['x']]],
    'integer' => [123],
    'float' => [1.5],
    'true' => [true],
    'false' => [false],
]);
