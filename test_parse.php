<?php
$attributes = [
  [
    "name" => "Afdichtingsspleet Tot",
    "visible" => true,
    "variation" => true,
    "options" => [ "4.99" ]
  ],
  [
    "name" => "Color",
    "visible" => true,
    "variation" => true,
    "options" => [ "Black" ]
  ]
];

        $flat_attributes = [];
        foreach ( $attributes as $key => $value ) {
            if ( is_int( $key ) && is_array( $value ) ) {
                // Nested group format: [ [ "key" => "val" ], ... ]
                foreach ( $value as $k => $v ) {
                    $flat_attributes[ $k ] = $v;
                }
            } else {
                // Flat format: { "key": "val", ... }
                $flat_attributes[ $key ] = $value;
            }
        }

var_dump($flat_attributes);
