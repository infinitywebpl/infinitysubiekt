<?php
/**
 * Uprawnienia pracownika do zakładek BO, zgodnie z PS 1.6 (Profile) i 1.7+ (Access).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektAccess
{
    const VIEW = 'view';
    const EDIT = 'edit';

    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * @param string $tabClass np. AdminCustomers
     * @param string $right self::VIEW albo self::EDIT
     *
     * @return bool
     */
    public function can($tabClass, $right)
    {
        $employee = $this->context->employee;
        if (!Validate::isLoadedObject($employee)) {
            return false;
        }

        $idProfile = (int) $employee->id_profile;
        if ($idProfile === (int) _PS_ADMIN_PROFILE_) {
            return true;
        }

        if (InfinitySubiektCompat::isPs17()) {
            $role = 'ROLE_MOD_TAB_' . strtoupper($tabClass) . '_' . ($right === self::EDIT ? 'UPDATE' : 'READ');

            return (bool) Access::isGranted($role, $idProfile);
        }

        // zakładki z nowszych wersji (np. AdminModulesSf) w 1.6 nie istnieją
        $idTab = (int) Tab::getIdFromClassName($tabClass);
        if (!$idTab) {
            return false;
        }

        $access = Profile::getProfileAccess($idProfile, $idTab);

        return isset($access[$right]) && (int) $access[$right] === 1;
    }

    /**
     * @param string[] $tabClasses
     * @param string $right
     *
     * @return bool
     */
    public function canAny(array $tabClasses, $right)
    {
        foreach ($tabClasses as $tabClass) {
            if ($this->can($tabClass, $right)) {
                return true;
            }
        }

        return false;
    }
}
