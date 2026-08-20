<?php

namespace App\Support\Import;

use RuntimeException;

/**
 * Thrown to unwind a preview's transaction once the sheet has been read. It is
 * a control signal, never a failure — see RunsExcelImports::runImport().
 */
class DryRunRollback extends RuntimeException {}
