<?php namespace Atomino\Molecules\Magic;

use Atomino\Cli\Style;
use Atomino\Entity\Entity;
use Atomino\Entity\Field\EnumField;
use Atomino\Entity\Field\SetField;
use Atomino\Entity\Model;
use Atomino\Molecules\EntityPlugin\Attachmentable\Attributes\AttachmentCollection;
use Atomino\Neutrons\CodeFinder;
use CaseHelper\CamelCaseHelper;
use CaseHelper\PascalCaseHelper;
use Symfony\Component\Console\Style\StyleInterface;
use function Atomino\dic;

class Generator {
	public function __construct(
		protected string $entityNamespace,
		protected string $apiNamespace,
		protected string $descriptorPath,
		protected Style $style) {
		if (!is_dir($this->descriptorPath)) {
			mkdir($this->descriptorPath);
		}
	}

	public function generate(string $entity) {
		/** @var Entity $entityClass */
		$entityClass = $this->entityNamespace . '\\' . $entity;
		$descriptor = ['fields' => [], 'collections' => [], 'relations' => []];
		$translate = [
			'{{namespace}}'   => $this->apiNamespace,
			'{{entity}}'      => $entityClass,
			'{{entity_name}}' => $entity,
		];

		/** @var Model $model */
		$model = ($entityClass)::model();

		$codeFinder = dic()->get(CodeFinder::class);
		$file = $codeFinder->Psr4ResolveClass($this->apiNamespace . '\\' . $entity . 'Magic');

		$file = (new PascalCaseHelper())->toKebabCase($entity . 'Model');
		$files = [
			'json' => $this->descriptorPath . $file . '.json',
			'ts'   => $this->descriptorPath . $file . '.ts',
			'api'  => $codeFinder->Psr4ResolveClass($this->apiNamespace . '\\' . $entity . 'Magic'),
			'selector'  => $codeFinder->Psr4ResolveClass($this->apiNamespace . '\\' . $entity . 'MagicSelector'),
		];

		$pd = json_decode(file_get_contents($files['json']), true);

		$this->style->_task('Fetching model data');

		foreach (AttachmentCollection::all(new \ReflectionClass($entityClass)) as $collection) {
			$descriptor['collections'][$collection->field] = [
				"name"      => $collection->field,
				"label"     => isset($pd['collections'][$collection->field]['label']) ? $pd['collections'][$collection->field]['label'] : $collection->field,
				"max-count" => $collection->maxCount,
				"max-size"  => $collection->maxSize,
				"mime-type" => $collection->mimetype,
			];
		}

		foreach ($model->getFields() as $name => $field) {
			$ref = new \ReflectionClass($field);
			$descriptor['fields'][$name] = [
				'field'     => $name,
				'label'    => isset($pd['fields'][$name]['label']) ? $pd['fields'][$name]['label'] : $name,
				'type'     => $ref->getShortName(),
				'readonly' => is_null($field->getSetter()) && $field->isProtected() ? true : false,
			];
			if ($field instanceof EnumField || $field instanceof SetField) {
				$descriptor['fields'][$name]['options'] = array_combine($field->getOptions(), $field->getOptions());
				foreach ($descriptor['fields'][$name]['options'] as $option => $value) {
					if (isset($pd['fields'][$name]['options'][$option])) {
						$descriptor['fields'][$name]['options'][$option] = $pd['fields'][$name]['options'][$option];
					}
				}
			}
		}

		foreach ($model->getRelations() as $name => $relation) {
			$ref = new \ReflectionClass($relation);
			$descriptor['relations'][$name] = [
				"label"  => isset($pd['relations'][$name]['label']) ? $pd['relations'][$name]['label'] : $name,
				"name"   => $name,
				"type"   => $ref->getShortName(),
				"target" => $relation->target,
				"field"  => $relation->field,
				"entity" => (new \ReflectionClass($relation->entity))->getShortName(),
			];
		}
		$this->style->_task_ok('done');



		$this->style->_task('Create Magic Api PHP');
		if (file_exists($files['api'])) {
			$this->style->_task_ok('File already exists');
		} else {
			$template = file_get_contents(__DIR__ . '/$resources/api.txt');
			$template = strtr($template, $translate);
			file_put_contents($files['api'], $template);
			$this->style->_task_ok('done');
		}
		$this->style->_task('Create Magic Selector PHP');
		if (file_exists($files['selector'])) {
			$this->style->_task_ok('File already exists');
		} else {
			$template = file_get_contents(__DIR__ . '/$resources/selector.txt');
			$template = strtr($template, $translate);
			file_put_contents($files['selector'], $template);
			$this->style->_task_ok('done');
		}


		$this->style->_task('Create Magic Descriptor JSON');
		$output = json_encode($descriptor, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE);
		file_put_contents($files['json'], $output);
		$this->style->_task_ok('done');


		$this->style->_task('Create Magic Descriptor Module TS File');
		$output = "import " . lcfirst($entity) . "Model from \"./" . $file . ".json\";\nexport default " . lcfirst($entity) . "Model;";
		file_put_contents($files['ts'], $output);
		$this->style->_task_ok('done');
	}
}