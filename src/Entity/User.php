<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'user')]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'string', length: 64, nullable: false)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private string $id;

    // Selon la migration 20251125103336 : first_name, last_name, email NOT NULL; picture BLOB nullable
    #[ORM\Column(name: 'first_name', type: 'string', length: 128, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(name: 'last_name', type: 'string', length: 128, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(name: 'email', type: 'string', length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'params', type: 'string', nullable: true)]
    private ?string $params = null;

    // BLOB en base; Doctrine recommande un type LOB. On utilise string|null pour simplicité.
    #[ORM\Column(name: 'picture', type: 'blob', nullable: true)]
    private ?string $picture = null;

    /*
     * Getter and Setter
     */
    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $val): void
    {
        $this->id = $val;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $val): void
    {
        $this->firstName = $val;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $val): void
    {
        $this->lastName = $val;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $val): void
    {
        $this->email = $val;
    }

    public function getParams(): ?array
    {
        if ($this->params === null) {
            return null;
        }

        try {
            return json_decode($this->params, true, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    public function setParams(?array $params): void
    {
        if ($params === null) {
            $this->params = null;
            return;
        }

        $this->params = json_encode($params, JSON_THROW_ON_ERROR);
    }

    public function getPicture(): ?string
    {
        return $this->picture;
    }

    public function setPicture(?string $picture): void
    {
        $this->picture = $picture;
    }
}
