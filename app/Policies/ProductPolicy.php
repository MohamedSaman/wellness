<?php

namespace App\Policies;

/** Catalog: everyone with products.view can look, products.manage can change. */
class ProductPolicy extends PermissionPolicy {}
