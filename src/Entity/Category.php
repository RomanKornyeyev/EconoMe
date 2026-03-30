<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: "App\Repository\CategoryRepository")]
#[ORM\Table(name: "category")]
#[ORM\HasLifecycleCallbacks]
class Category
{
    use TimestampableEntity;

    public const TYPE_EXPENSE = 'expense';
    public const TYPE_INCOME = 'income';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private User $user;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: "children")]
    #[ORM\JoinColumn(nullable: true, onDelete: "SET NULL")]
    private ?Category $parent = null;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: "parent")]
    private Collection $children;

    #[ORM\Column(type: "string", length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 100)]
    private ?string $name = null;

    #[ORM\Column(type: "string", length: 50, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(type: "string", length: 7, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: "string", length: 10)]
    #[Assert\Choice([self::TYPE_EXPENSE, self::TYPE_INCOME])]
    private string $type = self::TYPE_EXPENSE;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getParent(): ?Category
    {
        return $this->parent;
    }

    public function setParent(?Category $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function isRootCategory(): bool
    {
        return $this->parent === null;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }
}
