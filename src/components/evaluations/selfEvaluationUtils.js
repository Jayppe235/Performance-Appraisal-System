export function normalizeRoleForSelfEvaluation(role) {
  if (role === 'program_head' || role === 'programHead') return 'programHead';
  if (role === 'teacher') return 'faculty';
  return role || 'faculty';
}

export function isSelfEvaluationAssignment(evaluation) {
  if (!evaluation) return false;
  const text = [
    evaluation.assignmentType,
    evaluation.assignment_type,
    evaluation.assignmentTypeLabel,
    evaluation.relationshipTag,
    evaluation.relationship_tag,
    evaluation.questionnaireType,
    evaluation.questionnaire_type,
    evaluation.formType,
    evaluation.form_type,
    evaluation.title,
    evaluation.label,
    evaluation.type,
  ].filter(Boolean).join(' ').toLowerCase();

  return text.includes('self')
    || text.includes('self-evaluation')
    || text.includes('self evaluation')
    || text.includes('form a self')
    || text.includes('form b self')
    || text.includes('pmas form a self')
    || text.includes('pmas form b self');
}
