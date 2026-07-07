<?php
/**
 * Access module bootstrap.
 *
 * First-party admin for the core identity + tenancy substrate: Users (thin
 * identity) and Organizations (tenants). Grouped as one module because the domain
 * is tightly coupled through org_user membership (and it scales here later: roles,
 * memberships). Screens render in the PUMA admin shell; writes go through the /api
 * services (Access_Service_User / Access_Service_Org), lists through the shared
 * DataTables grid.
 *
 * Extending Zend_Application_Module_Bootstrap gives the module its resource
 * autoloader (Access_Service_* -> services/, Access_Form_* -> forms/).
 */
class Access_Bootstrap extends Zend_Application_Module_Bootstrap
{
}
