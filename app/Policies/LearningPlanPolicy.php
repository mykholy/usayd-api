<?php

namespace App\Policies;

use App\Models\LearningPlan;
use App\Models\User;

class LearningPlanPolicy
{
    /**
     * Determine whether the user can view the learning plan.
     * Matrix:
     * - Learner: Own data only (O)
     * - Teacher: Assigned student only (A)
     * - Academic: Role-authorized supervision (R)
     * - Admin: Full audit/override access (R)
     * - Support/Finance: Denied (-)
     */
    public function view(User $user, LearningPlan $learningPlan): bool
    {
        if ($user->isAdmin() || $user->isAcademic()) {
            return true;
        }

        if ($user->isTeacher() && $learningPlan->teacher_id === $user->id) {
            return true;
        }

        if ($user->isLearner() && $learningPlan->user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can update the learning plan.
     */
    public function update(User $user, LearningPlan $learningPlan): bool
    {
        if ($user->isAdmin() || $user->isAcademic()) {
            return true;
        }

        if ($user->isTeacher() && $learningPlan->teacher_id === $user->id) {
            return true;
        }

        if ($user->isLearner() && $learningPlan->user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Privileged override / approval: Academic or Admin only.
     */
    public function override(User $user, LearningPlan $learningPlan): bool
    {
        return $user->isAdmin() || $user->isAcademic();
    }
}
