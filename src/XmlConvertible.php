<?php

namespace Horat1us;

use Horat1us\Arrays\Collection;
use Horat1us\Examples\Head;

/**
 * Class XmlConvertible
 * @package Horat1us
 */
trait XmlConvertible
{
    /**
     * @var XmlConvertibleInterface[]|\DOMNode[]|\DOMElement[]|null
     */
    public $xmlChildren;

    /**
     * Name of xml element (class name will be used by default)
     *
     * @var string
     */
    public $xmlElementName;

    /**
     * @param \DOMDocument|null $document
     * @return \DOMElement
     */
    public function toXml(\DOMDocument $document = null): \DOMElement
    {
        if (!$document) {
            $document = new \DOMDocument();
        }

        $xml = $document->createElement(
            $this->getXmlElementName()
        );
        if (!is_null($this->xmlChildren)) {
            foreach ((array)$this->xmlChildren as $child) {
                if ($child instanceof XmlConvertibleInterface) {
                    $xml->appendChild($child->toXml($document));
                } elseif ($child instanceof \DOMNode || $child instanceof \DOMElement) {
                    $xml->appendChild($child);
                } else {
                    throw new \UnexpectedValueException(
                        "Each child element must be an instance of " . XmlConvertibleInterface::class
                    );
                }
            }
        }

        $properties = $this->getXmlProperties();
        foreach ($properties as $property) {
            $value = $this->{$property};
            if (is_array($value) || is_object($value) || is_null($value)) {
                continue;
            }
            $xml->setAttribute($property, $value);
        }

        return $xml;
    }

    /**
     * @param XmlConvertibleInterface $xml
     * @param XmlConvertibleInterface|null $target
     * @param bool $skipEmpty
     * @return XmlConvertible|XmlConvertibleInterface|null
     */
    public function xmlIntersect(
        XmlConvertibleInterface $xml,
        XmlConvertibleInterface $target = null,
        bool $skipEmpty = true
    )
    {
        $current = $this;
        $compared = $xml;

        if ($current->getXmlElementName() !== $compared->getXmlElementName()) {
            return null;
        }

        $newAttributes = [];
        foreach ($current->getXmlProperties() as $property) {
            if (!property_exists($compared, $property) || $current->{$property} !== $compared->{$property}) {
                continue;
            }
            $newAttributes[$property] = $compared->{$property};
        }

        $newChildren = array_uintersect(
            $compared->xmlChildren ?? [],
            $current->xmlChildren ?? [],
            function ($comparedChild, $currentChild) use ($skipEmpty) {
                if ($comparedChild === $currentChild) {
                    return 0;
                }
                if ($currentChild instanceof XmlConvertibleInterface) {
                    if (!$comparedChild instanceof XmlConvertibleInterface) {
                        return -1;
                    }
                    $intersected = $currentChild->xmlIntersect($comparedChild, null, $skipEmpty) !== null;
                    return $intersected ? 0 : -1;
                }
                if ($comparedChild instanceof XmlConvertibleInterface) {
                    return -1;
                }
                /** @var \DOMElement $comparedChild */
                $comparedChildObject = XmlConvertibleObject::fromXml($comparedChild);
                $currentChildObject = XmlConvertibleObject::fromXml($currentChild);
                return ($currentChildObject->xmlIntersect($comparedChildObject, null, $skipEmpty) !== null)
                    ? 0 : -1;
            }
        );

        if ($skipEmpty && empty($newAttributes) && empty($newChildren)) {
            return null;
        }

        if (!$target) {
            $targetClass = get_class($current);
            $target = new $targetClass;
        }
        $target->xmlElementName = $current->xmlElementName;
        $target->xmlChildren = $newChildren;
        foreach ($newAttributes as $name => $value) {
            $target->{$name} = $value;
        }

        return $target;
    }

    /**
     * @param XmlConvertibleInterface $xml
     * @return XmlConvertibleInterface|XmlConvertible
     */
    public function xmlDiff(XmlConvertibleInterface $xml)
    {
        $current = $this;
        $compared = $xml;

        if($current->getXmlElementName() !== $compared->getXmlElementName()) {
            return clone $current;
        }

        foreach ($current->getXmlProperties() as $property) {
            if (!property_exists($compared, $property)) {
                return clone $current;
            }
            if ($current->{$property} !== $compared->{$property}) {
                return clone $current;
            }
        }
        if (empty($current->xmlChildren) && !empty($compared->xmlChildren)) {
            return clone $current;
        }

        $newChildren = Collection::from($current->xmlChildren ?? [])
            ->map(function ($child) use ($compared) {
                return array_reduce($compared->xmlChildren ?? [], function ($carry, $comparedChild) use ($child) {
                    if ($carry) {
                        return $carry;
                    }
                    if ($comparedChild === $child) {
                        return false;
                    }
                    if ($child instanceof XmlConvertibleInterface) {
                        if (!$comparedChild instanceof XmlConvertibleInterface) {
                            return false;
                        }
                        return $child->xmlDiff($comparedChild);
                    }
                    if ($comparedChild instanceof XmlConvertibleInterface) {
                        return false;
                    }
                    /** @var \DOMElement $comparedChild */
                    $comparedChildObject = XmlConvertibleObject::fromXml($comparedChild);
                    $currentChildObject = XmlConvertibleObject::fromXml($child);
                    $diff = $currentChildObject->xmlDiff($comparedChildObject);
                    if($diff) {
                        return $diff->toXml($child->ownerDocument);
                    }
                    return null;
                });
            })
            ->filter(function ($child) {
                return $child !== null;
            })
            ->array;

        if (empty($newChildren)) {
            return null;
        }

        $target = clone $current;
        $target->xmlChildren = $newChildren;

        return clone $target;
    }

    /**
     * Converts object to XML and compares it with given
     *
     * @param XmlConvertibleInterface $xml
     * @return bool
     */
    public function xmlEqual(XmlConvertibleInterface $xml): bool
    {
        $document = new \DOMDocument();
        $document->appendChild($this->toXml($document));
        $current = $document->saveXML();

        $document = new \DOMDocument();
        $document->appendChild($xml->toXml($document));
        $compared = $document->saveXML();

        return $current === $compared;
    }

    /**
     * Name of xml element (class name will be used by default)
     *
     * @return string
     */
    public function getXmlElementName(): string
    {
        return $this->xmlElementName ?? (new \ReflectionClass(get_called_class()))->getShortName();
    }

    /**
     * @param \DOMDocument|\DOMElement $document
     * @param array $aliases
     * @return static
     */
    public static function fromXml($document, array $aliases = [])
    {
        if ($document instanceof \DOMDocument) {
            return static::fromXml($document->firstChild, $aliases);
        }

        /** @var \DOMElement $document */
        if (!in_array(get_called_class(), $aliases)) {
            $aliases[(new \ReflectionClass(get_called_class()))->getShortName()] = get_called_class();
        }
        foreach ($aliases as $key => $alias) {
            if (is_object($alias)) {
                if (!$alias instanceof XmlConvertibleInterface) {
                    throw new \UnexpectedValueException(
                        "All aliases must be instance or class implements " . XmlConvertibleInterface::class,
                        1
                    );
                }
                $aliases[is_int($key) ? $alias->getXmlElementName() : $key] = $alias;
                continue;
            }
            if (!is_string($alias)) {
                throw new \UnexpectedValueException(
                    "All aliases must be instance or class implements " . XmlConvertibleInterface::class,
                    2
                );
            }
            $instance = new $alias;
            if (!$instance instanceof XmlConvertibleInterface) {
                throw new \UnexpectedValueException(
                    "All aliases must be instance of " . XmlConvertibleInterface::class,
                    3
                );
            }
            unset($aliases[$key]);
            $aliases[is_int($key) ? $instance->getXmlElementName() : $key] = $instance;
        }

        $nodeObject = $aliases[$document->nodeName] ?? new XmlConvertibleObject;
        $properties = $nodeObject->getXmlProperties();

        /** @var \DOMAttr $attribute */
        foreach ($document->attributes as $attribute) {
            if (!$nodeObject instanceof XmlConvertibleObject) {
                if (!in_array($attribute->name, $properties)) {
                    throw new \UnexpectedValueException(
                        get_class($nodeObject) . ' must have defined ' . $attribute->name . ' XML property',
                        4
                    );
                }
            }
            $nodeObject->{$attribute->name} = $attribute->value;
        }

        $nodeObject->xmlChildren = [];
        /** @var \DOMElement $childNode */
        foreach ($document->childNodes as $childNode) {
            $nodeObject->xmlChildren[] = static::fromXml($childNode, $aliases);
        }
        $nodeObject->xmlElementName = $document->nodeName;

        return $nodeObject;
    }

    /**
     * Getting array of property names which will be used as attributes in created XML
     *
     * @param array|null $properties
     * @return array|string[]
     */
    public function getXmlProperties(array $properties = null): array
    {
        $properties = $properties
            ? Collection::from($properties)
            : Collection::from((new \ReflectionClass(get_called_class()))->getProperties(\ReflectionProperty::IS_PUBLIC))
                ->map(function (\ReflectionProperty $property) {
                    return $property->name;
                });

        return $properties
            ->filter(function (string $property): bool {
                return !in_array($property, ['xmlChildren', 'xmlElementName']);
            })
            ->array;
    }

    /**
     * Cloning all children by default
     */
    public function __clone()
    {
        $this->xmlChildren = array_map(function ($xmlChild) {
            return clone $xmlChild;
        }, $this->xmlChildren ?? []) ?: null;
    }
}