<?php namespace Atomino\Molecules\Magic;

use Atomino\Database\Finder\Filter;
use Atomino\Entity\Entity;
use Atomino\Entity\ValidationError;
use Atomino\Molecules\EntityPlugin\Attachmentable\Attachmentable;
use Atomino\Molecules\Module\Attachment\Img\Img;
use Atomino\Molecules\Module\Authenticator\SessionAuthenticator;
use Atomino\Molecules\Responder\Api\Api;
use Atomino\Molecules\Responder\Api\Attributes\Auth;
use Atomino\Molecules\Responder\Api\Attributes\Route;

abstract class MagicApi extends Api {

	public function __construct(private SessionAuthenticator $authenticator) { }

	abstract protected function getEntity(): string;

	protected function getEntityObject($id = null): Entity {
		if (!is_null($id)) return ($this->getEntity())::pick($id);
		else return new ($this->getEntity())();
	}

	protected function preprocess($data){return $data;}
	protected function postprocess(Entity $item, $data){}

	#[Route(Api::POST, '/list/:page([0-9]+)')]
	#[Auth]
	public function list($page) {
		$limit = $this->getDataBag()->get('limit');
		$quickSearch = $this->getDataBag()->get('quickSearch', null);
		$sort = $this->getDataBag()->get('sort');

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
	protected function getSort(string $sort): array { return []; }

	#[Route(Api::GET, '/:id([0-9]+)')]
	#[Auth]
	public function get($id) {
		$item = ($this->getEntity())::pick($id);
		if ($item === null) {
			$this->getResponse()->setStatusCode(404);
			return;
		}
		return $item->export();
	}

	#[Route(Api::GET, '/blank')]
	#[Auth]
	public function blank() {
		$item = new ($this->getEntity())();
		return $item->export();
	}

	#[Route(Api::POST, '/:id([0-9]+)')]
	#[Auth]
	public function update($id) {
		return $this->save(($this->getEntity())::pick($id), $this->getDataBag()->get('item'));
	}

	#[Route(Api::POST, '/new')]
	#[Auth]
	public function create() {
		return $this->save(new ($this->getEntity())(), $this->getDataBag()->get('item'));
	}

	protected function save($item, $data) {
		$data = $this->preprocess($data);
		try {
			$item->import($data);
			$this->postprocess($item, $data);
			$item->save();
		} catch (ValidationError $e) {
			$this->getResponse()->setStatusCode(422);
			return $e->getMessages();
		} catch (\Exception $exception) {
			$this->getResponse()->setStatusCode(400);
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
		/** @var \Atomino\Molecules\Module\Attachment\AttachmentableInterface $entity */
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
			/** @var \Atomino\Molecules\Module\Attachment\AttachmentableInterface $entity */
			$entity = $this->getEntityObject($id);
			$entity->getAttachmentStorage()->collections[$this->getRequestBag()->get('collection')]->addFile($this->getFilesBag()->get('file'));
		}catch (\Exception $e){
			$this->getResponse()->setStatusCode(499, $e->getMessage());
			return;
		}
	}

	#[Route(Api::POST, '/attachments/delete/:id([0-9]+)')]
	#[Auth]
	public function deleteAttachment($id) {
		$filename = $this->getDataBag()->get('filename');
		$collection = $this->getDataBag()->get('collection');
		/** @var \Atomino\Molecules\Module\Attachment\AttachmentableInterface $entity */
		$entity = $this->getEntityObject($id);

		$entity->getAttachmentStorage()->collections[$collection]->remove($filename);

		return true;
	}

	#[Route(Api::POST, '/attachments/order/:id([0-9]+)')]
	#[Auth]
	public function orderAttachment($id) {
		$filename = $this->getDataBag()->get('filename');
		$collection = $this->getDataBag()->get('collection');
		$index = $this->getDataBag()->get('index');
		/** @var \Atomino\Molecules\Module\Attachment\AttachmentableInterface $entity */
		$entity = $this->getEntityObject($id);
		$entity->getAttachmentStorage()->collections[$collection]->order($filename, $index);

		return true;
	}

	#[Route(Api::POST, '/attachments/add/:id([0-9]+)')]
	#[Auth]
	public function addAttachment($id) {
		try {
			$filename = $this->getDataBag()->get('filename');
			$collection = $this->getDataBag()->get('collection');
			$from = $this->getDataBag()->get('from');
			/** @var \Atomino\Molecules\Module\Attachment\AttachmentableInterface $entity */
			$entity = $this->getEntityObject($id);
			$entity->getAttachmentStorage()->collections[$collection]->add($filename);

			if(!is_null($from)){
				$entity->getAttachmentStorage()->collections[$from]->remove($filename);
			}

			return true;
		}catch (\Exception $e){
			$this->getResponse()->setStatusCode(499, $e->getMessage());
			return;
		}
	}

	#[Route(Api::POST, '/attachments/modify/:id([0-9]+)')]
	#[Auth]
	public function modifyAttachment($id) {
		$filename = $this->getDataBag()->get('filename');
		$data = $this->getDataBag()->get('data');

		/** @var \Atomino\Molecules\Module\Attachment\AttachmentableInterface $entity */
		$entity = $this->getEntityObject($id);

		$file = $entity->getAttachmentStorage()->getAttachment($filename);

		$file->storage->begin();

		if (array_key_exists('title', $data)) $file->setTitle(trim($data['title']));
		if (array_key_exists('properties', $data)) $file->setProperties($data['properties']);
		if (array_key_exists('safezone', $data)) $file->setSafezone($data['safezone']);
		if (array_key_exists('focus', $data)) $file->setFocus($data['focus']);

		$file->storage->commit();

		if (array_key_exists('newname', $data) && $filename !== $data['newname'] && trim($data['newname'])) {
			$file->rename($data['newname']);
		}
		return true;
	}

}