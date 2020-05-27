<?php

namespace Dwo\UserCommandsBundle;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserMetadata
{
    const TYPE_ID = 'username';
    const TYPE_USERNAME = 'username';
    const TYPE_PASSWORD = 'password';
    const TYPE_ROLES = 'roles';

    /** @var EntityManagerInterface */
    private $em;
    /** @var string */
    private $userClassName;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->userClassName = $this->findUserClassName();
    }

    public function findUserClassName(): string
    {
        if (null !== $this->userClassName) {
            return $this->userClassName;
        }

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $classMetadata) {
            $className = $classMetadata->getName();
            $ref = new \ReflectionClass($className);
            if (in_array(UserInterface::class, $ref->getInterfaceNames())) {
                $this->userClassName = $className;

                return $className;
            }
        }

        throw new \Exception('no entity with interface %s was found', UserInterface::class);
    }

    public function findFieldByType(string $type)
    {
        $fields = $this->getFieldsByType();
        $stringFields = $fields['string'];
        $remainingFields = $fields['remaining'];

        $alternativeNames = [
            self::TYPE_USERNAME => ['email'],
            self::TYPE_PASSWORD => ['pw'],
        ];

        switch ($type) {
            case 'id':
                if (1 === count($fields['id'])) {
                    return current($fields['id']);
                } else {
                    throw new \Exception('there a more than one ID fields: '.implode(', ', $fields['id']));
                }
                break;

            case self::TYPE_USERNAME:
                if (1 === count($fields['unique'])) {
                    return current($fields['unique']);
                } else {
                    foreach (['email'] as $possibleNames) {
                        if (in_array($possibleNames, $stringFields)) {
                            return $possibleNames;
                        }
                    }
                }
                break;

            case self::TYPE_PASSWORD:
                if (in_array($type, $stringFields)) {
                    return $type;
                }

                foreach ($alternativeNames[$type] ?? [] as $altName) {
                    if (in_array($altName, $stringFields)) {
                        return $altName;
                    }
                }
                break;

            case 'roles':
                if (in_array($type, $remainingFields)) {
                    return $type;
                }

                foreach ($alternativeNames[$type] ?? [] as $altName) {
                    if (in_array($altName, $remainingFields)) {
                        return $altName;
                    }
                }
                break;
        }

        throw new \Exception(
            sprintf(
                'could not find a field with the name "%s" - fields are: %s',
                $type,
                implode(', ', array_merge($stringFields, $remainingFields))
            )
        );
    }

    private function getFieldsByType(): array
    {
        $return = [
            'id'        => [],
            'unique'    => [],
            'string'    => [],
            'remaining' => [],
        ];

        $metadata = $fieldMappings = $this->em->getClassMetadata($this->userClassName);

        foreach ($metadata->fieldMappings as $name => $mapping) {
            if ($metadata->isIdentifier($name)) {
                $return['id'][] = $name;
            } elseif ($metadata->isUniqueField($name)) {
                $return['unique'][] = $name;
            } elseif (in_array($mapping['type'], ['string'])) {
                $return['string'][] = $name;
            } else {
                $return['remaining'][] = $name;
            }
        }

        return $return;
    }
}