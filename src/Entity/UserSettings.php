<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Entity\Traits\TimestampableEntity;

#[ORM\Entity]
#[ORM\Table(name: "user_settings")]
#[ORM\HasLifecycleCallbacks]
class UserSettings
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: "settings")]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private User $user;

    #[ORM\Column(type: "boolean", options: ["default" => true])]
    private bool $isSearchable = true;

    #[ORM\Column(type: "boolean", options: ["default" => true])]
    private bool $allowFriendRequests = true;

    /**
     * Nombres de los tours de onboarding ya vistos (o saltados) por el usuario.
     * Ej: ["dashboard", "transactions", "categories"]
     *
     * @var string[]
     */
    #[ORM\Column(type: "json")]
    private array $completedTours = [];

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isSearchable(): bool
    {
        return $this->isSearchable;
    }

    public function setIsSearchable(bool $isSearchable): self
    {
        $this->isSearchable = $isSearchable;
        return $this;
    }

    public function allowFriendRequests(): bool
    {
        return $this->allowFriendRequests;
    }

    public function setAllowFriendRequests(bool $allowFriendRequests): self
    {
        $this->allowFriendRequests = $allowFriendRequests;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getCompletedTours(): array
    {
        return $this->completedTours;
    }

    public function hasCompletedTour(string $name): bool
    {
        return in_array($name, $this->completedTours, true);
    }

    public function markTourCompleted(string $name): self
    {
        if (!$this->hasCompletedTour($name)) {
            $this->completedTours[] = $name;
        }
        return $this;
    }
}
