<?php

$array = [
	'a' => 1,
	'b' => [
		'c' => 2,
		'd' => 3,
	],
	't' => [
		'cd' => 2,
		'd' => 3,
	],
	'e' => 4,
];
$subiterator = new \RecursiveArrayIterator($array);
$iterator = new \RecursiveIteratorIterator(
	$subiterator,
	\RecursiveIteratorIterator::SELF_FIRST,
	\RecursiveIteratorIterator::LEAVES_ONLY
);

var_dump($iterator->current());
die();

$do = false;
foreach ($iterator as $key => $value) {
	if($key === 'c') {
		$iterator->getSubIterator($iterator->getDepth())->offsetSet($key, 'new value');
	}
	echo "$key => $value\n";
}
print_r($subiterator->getArrayCopy());
