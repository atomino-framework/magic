<?php namespace Atomino\Molecules\Magic\Attributes;

use Atomino\Neutrons\Attr;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Magic extends Attr {
	public function __construct(public string $entity) { }
}