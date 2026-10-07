<?php

namespace App\Policies;

/**
 * Report cases carry their own permission family, so a role can be given the
 * report cases without the rest of the learning content.
 */
class ReportCasePolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'report_cases';
    }
}
