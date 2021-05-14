<?php namespace Atomino\Molecules\Magic;

use Atomino\Database\Finder\Comparison;
use Atomino\Database\Finder\Filter;
use Atomino\Entity\Entity;
use Atomino\Molecules\Magic\Attributes\Magic;
use Atomino\RequestPipeline\Responder\Api\Api;
use Atomino\RequestPipeline\Responder\Api\Attributes\Auth;
use Atomino\RequestPipeline\Responder\Api\Attributes\Route;


abstract class SelectorApi extends Api {

	/** @return Entity */
	private function getEntity(): string { return Magic::get(new \ReflectionClass($this))->entity; }
	abstract protected function filter(string $search): Filter;
	abstract protected function value(Entity $item): string;

	protected function order(): array { return ['id', 'asc']; }
	protected function map(Entity $item): array { return ["id" => $item->id, "value" => $this->value($item)]; }

	#[Route(Api::GET, 'search/:search(.*)/')]
	#[Auth]
	public function search(string $search) {
		$search = urldecode($search);
		$items = $this->getEntity()::search($this->filter($search))->order($this->order())->collect();
		return array_map(fn($item) => $this->map($item), $items);
	}

	#[Route(Api::GET, 'get/:id')]
	#[Route(Api::GET, 'get/')]
	#[Auth]
	public function get(string $id) {
		$ids = explode(',', $id);
		$items = $this->getEntity()::search(Filter::where((new Comparison('id'))->in($ids)))->order($this->order())->collect();
		return array_map(fn($item) => $this->map($item), $items);
	}
}