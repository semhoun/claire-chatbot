<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'user')]
#[ORM\Entity]
class User
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'string', length: 64, nullable: false)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    public string $id {
        get {
            return $this->id;
        }
        set {
            $this->id = $value;
        }
    }

    // Selon la migration 20251125103336 : first_name, last_name, email NOT NULL; picture BLOB nullable
    #[ORM\Column(name: 'first_name', type: 'string', length: 128, nullable: true)]
    public ?string $firstName = null {
        get {
            return $this->firstName;
        }
        set {
            $this->firstName = $value;
        }
    }

    #[ORM\Column(name: 'last_name', type: 'string', length: 128, nullable: true)]
    public ?string $lastName = null {
        get {
            return $this->lastName;
        }
        set {
            $this->lastName = $value;
        }
    }

    #[ORM\Column(name: 'email', type: 'string', length: 255, nullable: true)]
    public ?string $email = null {
        get {
            return $this->email;
        }
        set {
            $this->email = $value;
        }
    }

    #[ORM\Column(name: 'params', type: 'string', nullable: true)]
    public ?string $params = null {
        get {
            if ($this->params === null) {
                return null;
            }

            try {
                return json_decode($this->params, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }
        set {
            if ($value === null) {
                $this->params = null;
                return;
            }

            $this->params = json_encode($value, flags: JSON_THROW_ON_ERROR);
        }
    }

    // BLOB en base; Doctrine recommande un type LOB. On utilise string|null pour simplicité.
    #[ORM\Column(name: 'picture', type: 'blob', nullable: true)]
    private ?string $picture = null {
        get {
            return $this->picture;
        }
        set {
            $this->picture = $value;
        }
    }
}
