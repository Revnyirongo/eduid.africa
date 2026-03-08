<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @ORM\Entity
 * @ORM\Table(name="fos_user")
 */
class User implements UserInterface
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(name="username", type="string", length=180)
     */
    private $username;

    /**
     * @ORM\Column(name="email", type="string", length=180)
     */
    private $email;

    /**
     * @ORM\Column(name="sn", type="string", length=255)
     */
    private $sn;

    /**
     * @ORM\Column(name="givenName", type="string", length=255)
     */
    private $givenName;

    /**
     * Bidirectional
     *
     * @ORM\ManyToMany(targetEntity="IdP", mappedBy="users")
     * @ORM\JoinTable(name="user_idp")
     */
    private $idps;

    /**
     * @var string $googleAuthenticatorCode Stores the secret code
     * @ORM\Column(type="string", length=16, nullable=true)
     */
    private $googleAuthenticatorCode = null;

    public function __construct()
    {
        $this->idps = new ArrayCollection();
    }

    /**
     * Add idP
     *
     * @param \App\Entity\IdP $idP
     *
     * @return User
     */
    public function addIdP(\App\Entity\IdP $idP)
    {
        if (!$this->idps->contains($idP)) {
            $this->idps[] = $idP;
        }

        return $this;
    }

    /**
     * Remove idP
     *
     * @param \App\Entity\IdP $idP
     */
    public function removeIdP(\App\Entity\IdP $idP)
    {
        $this->idps->removeElement($idP);
    }

    /**
     * Get idPs
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getIdPs(): Collection
    {
        return $this->idps;
    }

    /**
     * Gets the value of id.
     *
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
        // No-op: we do not store temporary sensitive data here.
    }

    /**
     * Backwards-compatible alias for older code that expects getUsername().
     */
    public function getUsername(): string
    {
        return (string) $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function getEmail(): string
    {
        return (string) $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    /**
     * Gets the value of sn.
     *
     * @return mixed
     */
    public function getSn()
    {
        return $this->sn;
    }

    /**
     * Sets the value of sn.
     *
     * @param mixed $sn the sn
     *
     * @return self
     */
    public function setSn($sn)
    {
        $this->sn = $sn;

        return $this;
    }

    /**
     * Gets the value of givenName.
     *
     * @return mixed
     */
    public function getGivenName()
    {
        return $this->givenName;
    }

    /**
     * Sets the value of givenName.
     *
     * @param mixed $givenName the given name
     *
     * @return self
     */
    public function setGivenName($givenName)
    {
        $this->givenName = $givenName;

        return $this;
    }

    /**
     * Set googleAuthenticatorCode
     *
     * @param string $googleAuthenticatorCode
     *
     * @return User
     */
    public function setGoogleAuthenticatorCode($googleAuthenticatorCode)
    {
        $this->googleAuthenticatorCode = $googleAuthenticatorCode;

        return $this;
    }

    /**
     * Get googleAuthenticatorCode
     *
     * @return string
     */
    public function getGoogleAuthenticatorCode()
    {
        return $this->googleAuthenticatorCode;
    }
}
