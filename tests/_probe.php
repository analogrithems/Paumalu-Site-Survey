<?php
$payload = "\x89PNG\r\n\x1a\n" . str_repeat( 'A', 64 );
$size = @getimagesizefromstring( $payload );
var_dump( $size );
echo "IMAGETYPE_PNG=" . IMAGETYPE_PNG . "\n";
$r = new ReflectionMethod( 'Paumalu\SiteSurvey\Proposal\Signature', 'decode_png' );
$r->setAccessible( true );
$out = $r->invoke( null, 'data:image/png;base64,' . base64_encode( $payload ) );
echo is_wp_error( $out ) ? "REJECTED: " . $out->get_error_code() . "\n" : "ACCEPTED (" . strlen( $out ) . " bytes)\n";
