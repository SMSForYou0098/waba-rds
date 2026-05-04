<?php

use App\Http\Controllers\Report\ExportHandleController;
use Illuminate\Support\Facades\Route;

// Note: These routes are currently handled directly by ExportHandleController in the Report domain.
// Having a separate export file might be redundant if all exports use that controller. 
// For now, these routes are kept in report.php. This file can be used if export logic diverges later.
