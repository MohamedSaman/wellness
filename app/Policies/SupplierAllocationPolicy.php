<?php

namespace App\Policies;

/** Allocations of payments and credits to invoices: read like payables; made and undone through SupplierPayablesService. */
class SupplierAllocationPolicy extends SupplierPayablePolicy {}
