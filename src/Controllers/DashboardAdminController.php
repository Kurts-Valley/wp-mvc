<?php
declare(strict_types=1);

namespace KurtCampbell\WpMvc\Controllers;

/**
 * Marker base for the top-level admin dashboard page.
 *
 * Product plugins may extend this with a concrete controller under includes/controllers/admin/.
 * When none is registered, {@see \KurtCampbell\WpMvc\PluginKernel} still creates the parent menu.
 */
abstract class DashboardAdminController extends AdminController {
}
