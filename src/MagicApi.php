<?php namespace Atomino\Magic;

use Atomino\Bundle\Authenticate\Authenticator;
use Atomino\Carbon\Database\Finder\Filter;
use Atomino\Carbon\Entity;
use Atomino\Carbon\ValidationError;
use Atomino\Carbon\Plugins\Attachment\Attachmentable;
use Atomino\Magic\Attributes\Magic;
use Atomino\Bundle\Attachment\Img\Img;
use Atomino\Bundle\Authenticate\SessionAuthenticator;
use Atomino\Mercury\Responder\Api\Api;
use Atomino\Mercury\Responder\Api\Attributes\Auth;
use Atomino\Mercury\Responder\Api\Attributes\Route;

abstract class MagicApi extends Api {

	private string $entity;
	public function __construct(private SessionAuthenticator $sessionAuthenticator, Authenticator $authenticator) {
		$this->entity = Magic::get(new \ReflectionClass($this))->entity;
	}
	private function getEntity(): string { return $this->entity; }

	protected function getEntityObject($id = null): Entity {
		if (!is_null($id)) return ($this->getEntity())::pick($id);
		else return ($this->getEntity())::create();
	}

	protected function preprocess($data) { return $data; }
	protected function postprocess(Entity $item, $data) { }
	protected function getSort(string $sort): array { return []; }
	protected function quickSearch(string $quickSearch): Filter { return Filter::where(($this->getEntity())::id($quickSearch)); }


	#[Route(Api::POST, '/list/:page([0-9]+)')]
	#[Auth]
	public function list($page) {
		$limit = $this->data->get('limit');
		$quickSearch = $this->data->get('quickSearch', null);
		$sort = $this->data->get('sort');

		$search = ($this->getEntity())::search(Filter::where($quickSearch ? $this->quickSearch($quickSearch) : false));
		if ($sort) {
			if (is_string($sort)) $sort = $this->getSort($sort);
			$search->order(...$sort);
		}

		$count = null;
		$items = $search->page($limit, $page, $count);
		$calculatedMaxPage = ceil($count / $limit);
		if ($page > $calculatedMaxPage && $count) {
			$page = $calculatedMaxPage;
			$items = $search->page($limit, $page, $count);
		}

		return [
			'page'  => $page,
			'count' => $count,
			'items' => $items,
		];
	}

	#[Route(Api::GET, '/:id([0-9]+)')]
	#[Auth]
	public function get($id) {
		$item = ($this->getEntity())::pick($id);
		if ($item === null) {
			$this->setStatusCode(404);
			return;
		}
		return $item->export();
	}

	#[Route(Api::GET, '/blank')]
	#[Auth]
	public function blank() {
		$item = ($this->getEntity())::create();
		return $item->export();
	}

	#[Route(Api::POST, '/:id([0-9]+)')]
	#[Auth]
	public function update($id) {
		return $this->save(($this->getEntity())::pick($id), $this->data->get('item'));
	}

	#[Route(Api::POST, '/new')]
	#[Auth]
	public function create() {
		return $this->save(($this->getEntity())::create(), $this->data->get('item'));
	}

	protected function save($item, $data) {
		$data = $this->preprocess($data);
		try {
			$item->import($data);
			$this->postprocess($item, $data);
			$item->save();
		} catch (ValidationError $e) {
			$this->setStatusCode(422);
			return $e->getMessages();
		} catch (\Exception $exception) {
			$this->setStatusCode(400);
			return $exception->getMessage();
		}
		return $item->export();
	}

	#[Route(Api::DELETE, '/:id([0-9]+)')]
	#[Auth]
	public function delete($id) {
		($this->getEntity())::pick($id)->delete();
	}

	#[Route(Api::GET, '/attachments/:id([0-9]+)/:category')]
	#[Auth]
	public function getAttachments(int $id, string $category) {
		/** @var \Atomino\Bundle\Attachment\AttachmentableInterface $entity */
		$entity = $this->getEntityObject($id);
		$files = [];
		foreach ($entity->getAttachmentStorage()->collections[$category] as $attachment) {
			$files[] = [
				"name"       => $attachment->filename,
				"url"        => $attachment->url,
				"size"       => $attachment->size,
				"properties" => $attachment->getProperties(),
				"mime"       => $attachment->mimetype,
				"title"      => $attachment->title,
				"isImage"    => $attachment->isImage,
				"thumbnail"  => $attachment->isImage ? $attachment->image->crop(100, 100)->png : false,
				"width"      => $attachment->width,
				"height"     => $attachment->height,
				"safezone"   => $attachment->safezone,
				"focus"      => $attachment->focus,
			];
		}
		return $files;
	}

	#[Route(Api::POST, '/attachments/upload/:id([0-9]+)')]
	#[Auth]
	public function uploadAttachment($id) {
		try {
			/** @var \Atomino\Bundle\Attachment\AttachmentableInterface $entity */
			$entity = $this->getEntityObject($id);
			$entity->getAttachmentStorage()->collections[$this->post->get('collection')]->addFile($this->files->get('file'));
		} catch (\Exception $e) {
			$this->getResponse()->setStatusCode(499, $e->getMessage());
			return;
		}
	}

	#[Route(Api::POST, '/attachments/delete/:id([0-9]+)')]
	#[Auth]
	public function deleteAttachment($id) {
		$filename = $this->data->get('filename');
		$collection = $this->data->get('collection');
		/** @var \Atomino\Bundle\Attachment\AttachmentableInterface $entity */
		$entity = $this->getEntityObject($id);

		$entity->getAttachmentStorage()->collections[$collection]->remove($filename);

		return true;
	}

	#[Route(Api::POST, '/attachments/order/:id([0-9]+)')]
	#[Auth]
	public function orderAttachment($id) {
		$filename = $this->data->get('filename');
		$collection = $this->data->get('collection');
		$index = $this->data->get('index');
		/** @var \Atomino\Bundle\Attachment\AttachmentableInterface $entity */
		$entity = $this->getEntityObject($id);
		$entity->getAttachmentStorage()->collections[$collection]->order($filename, $index);

		return true;
	}

	#[Route(Api::POST, '/attachments/add/:id([0-9]+)')]
	#[Auth]
	public function addAttachment($id) {
		try {
			$filename = $this->data->get('filename');
			$collection = $this->data->get('collection');
			$from = $this->data->get('from');
			/** @var \Atomino\Bundle\Attachment\AttachmentableInterface $entity */
			$entity = $this->getEntityObject($id);
			$entity->getAttachmentStorage()->collections[$collection]->add($filename);

			if (!is_null($from)) {
				$entity->getAttachmentStorage()->collections[$from]->remove($filename);
			}

			return true;
		} catch (\Exception $e) {
			$this->getResponse()->setStatusCode(499, $e->getMessage());
			return;
		}
	}

	#[Route(Api::POST, '/attachments/modify/:id([0-9]+)')]
	#[Auth]
	public function modifyAttachment($id) {
		$filename = $this->data->get('filename');
		$data = $this->data->get('data');

		/** @var \Atomino\Bundle\Attachment\AttachmentableInterface $entity */
		$entity = $this->getEntityObject($id);

		$file = $entity->getAttachmentStorage()->getAttachment($filename);

		$file->storage->begin();

		if (array_key_exists('title', $data)) $file->setTitle(trim($data['title']));
		if (array_key_exists('properties', $data)) $file->setProperties($data['properties']);
		if (array_key_exists('safezone', $data)) $file->setSafezone($data['safezone']);
		if (array_key_exists('focus', $data)) $file->setFocus($data['focus']);
		if (array_key_exists('quality', $data)) $file->setQuality($data['quality']);

		$file->storage->commit();

		if (array_key_exists('newname', $data) && $filename !== $data['newname'] && trim($data['newname'])) {
			$file->rename($data['newname']);
		}
		return true;
	}

}