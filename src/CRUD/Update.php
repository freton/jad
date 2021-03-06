<?php

namespace Jad\CRUD;

use Jad\Common\ClassHelper;
use Jad\Common\Text;
use Jad\Map\Annotations\Header;
use Jad\Response\ValidationErrors;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Component\Validator\Validation;

/**
 * Class Update
 * @package Jad\CRUD
 */
class Update extends AbstractCRUD
{
    /**
     * @throws \Exception
     */
    public function updateResource()
    {
        $input          = $this->request->getInputJson();
        $type           = $input->data->type;
        $id             = $this->request->getId();
        $attributes     = isset($input->data->attributes) ? (array) $input->data->attributes : [];
        $mapItem        = $this->mapper->getMapItem($type);
        $entityClass    = $mapItem->getEntityClass();
        $reader         = new AnnotationReader();
        $reflection     = new \ReflectionClass($mapItem->getEntityClass());
        $entity         = $this->mapper->getEm()->getRepository($mapItem->getEntityClass())->find($id);

        if(!$entity instanceof $entityClass) {
            throw new \Exception('Entity not found');
        }

        $header = $reader->getClassAnnotation($reflection, Header::class);

        if(!is_null($header)) {
            if(property_exists($header, 'readOnly')) {
                $readOnly = is_null($header->readOnly) ? false : (bool) $header->readOnly;

                if($readOnly) {
                    return;
                }
            }
        }

        foreach($attributes as $attribute => $value) {
            $attribute = Text::deKebabify($attribute);

            if(!$mapItem->getClassMeta()->hasField($attribute)) {
                continue;
            }

            $jadAnnotation = $reader->getPropertyAnnotation(
                $reflection->getProperty($attribute),
                'Jad\Map\Annotations\Attribute'
            );

            if(!is_null($jadAnnotation)) {
                if(property_exists($jadAnnotation, 'readOnly')) {
                    $readOnly = is_null($jadAnnotation->readOnly) ? true : (bool) $jadAnnotation->readOnly;

                    if($readOnly) {
                        continue;
                    }
                }
            }

            // Update value
            ClassHelper::setPropertyValue($entity, $attribute, $value);
        }

        /**
         * Validate input
         */
        $validator = Validation::createValidatorBuilder()->enableAnnotationMapping()->getValidator();
        $errors = $validator->validate($entity);

        if (count($errors) > 0) {
            $error = new ValidationErrors($errors);
            $error->render();
            exit(1);
        }

        $relationships = isset($input->data->relationships) ? (array) $input->data->relationships : [];

        foreach($relationships as $relatedType => $related) {
            $relatedData = $related->data;
            $related = is_array($relatedData) ? $relatedData : [$relatedData];
            $relatedProperty = ClassHelper::getPropertyValue($entity, $relatedType);

            foreach($related as $relationship) {
                $type = $relationship->type;
                $id = $relationship->id;
                ;
                $relationalMapItem = $this->mapper->getMapItem($type);
                $relationalClass = $relationalMapItem->getEntityClass();

                $reference = $this->mapper->getEm()->getReference($relationalClass, $id);

                if($relatedProperty instanceof DoctrineCollection) {
                    // First try entity add method, else add straight to collection
                    $method1 = 'add' . ucfirst($type);
                    $method2 = 'add' . ucfirst($relatedType);
                    $method = method_exists($entity, $method1) ? $method1 : $method2;

                    if(method_exists($entity, $method)) {
                        $entity->$method($reference);
                    } else {
                        $relatedProperty->add($reference);
                    }
                } else {
                    ClassHelper::setPropertyValue($entity, $relatedType, $reference);
                }
            }
        }

        $this->mapper->getEm()->flush();
    }
}