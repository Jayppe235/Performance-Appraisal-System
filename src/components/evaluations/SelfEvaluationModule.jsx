import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { AlertCircle, ArrowRight, CalendarRange, CheckCircle2, PartyPopper, Plus, RotateCcw, Save, Send, Trash2, Upload } from 'lucide-react';
import apiFetch from '../../data/api.js';
import { apiUrl } from '../../data/apiBase.js';
import { addToast } from '../common/Toast.jsx';
import { confirmProceed, confirmSaveChanges, confirmSubmitEvaluation } from '../common/ConfirmationModal.jsx';
import { DynamicQuestionnaireBuilder, DynamicQuestionnaireRenderer } from './DynamicSelfQuestionnaire.jsx';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';
import PeriodSelector from './PeriodSelector.jsx';

const emptyAnswers = {
  achievedGoals: [{ goals: '', accomplishment: '' }],
  otherAccomplishments: '',
  unmetGoalsReason: '',
  personalStrengths: '',
  overallSelfRating: '',
  ratingBasis: '',
  furtherContribution: '',
  performanceOutputs: [{ goals: '', weight: '', accomplishment: '', rating: '' }],
  performanceFactorsScore: '',
  appraiseeStrengths: '',
  improvementPlans: [{ area: '', actionPlan: '', timeFrame: '' }],
  comments: '',
  confirmations: { appraisee: '', appraiseeSignature: '', appraiseeSignatureName: '', appraiser: '', reviewer: '', date: new Date().toISOString().slice(0, 10) },
  careerDevelopment: {
    nextJob: '',
    status: '',
    developmentTime: '',
    actionPlans: [{ assistance: '', difficulties: '', actionSteps: '', timeTable: '' }],
    appraiser: '',
    reviewer: '',
    date: new Date().toISOString().slice(0, 10),
  },
  selfRatings: {},
  selfEvidence: {},
  dynamicResponses: {},
};

const ratings = [
  { label: 'Exceptional', value: 'Exceptional' },
  { label: 'Exceeds Expectations', value: 'Exceeds Expectations' },
  { label: 'Meets Expectations', value: 'Meets Expectations' },
  { label: 'Meets Most Expectations', value: 'Meets Most Expectations' },
  { label: 'Does Not Meet Expectations', value: 'Does Not Meet Expectations' },
];

const outputRatings = [
  { code: 'E', label: 'E = 5', value: 5 },
  { code: 'EE', label: 'EE = 4', value: 4 },
  { code: 'ME', label: 'ME = 3', value: 3 },
  { code: 'MM', label: 'MM = 2', value: 2 },
  { code: 'DE', label: 'DE = 1', value: 1 },
];

const ratingLabels = {
  5: 'Highly Evident',
  4: 'Evident',
  3: 'Moderately Evident',
  2: 'Slightly Evident',
  1: 'Not Evident',
};

function formatReviewDate(value) {
  if (!value) return '';
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString([], {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

const careerStatuses = [
  'Ready for the most probable next job',
  'High potential for most probable next job but would need development interventions',
  'May need career shift to another line of work / another department',
  'Suitability limited to present position only',
];

const developmentPeriods = [
  'Within 3 months',
  'Within 6 months',
  'Within 1 year',
  'Within 2 years',
  'More than 2 years',
];

const templateFields = [
  ['question1', 'Question 1'],
  ['question2', 'Question 2'],
  ['question3', 'Question 3'],
  ['question4', 'Question 4'],
  ['question5', 'Question 5'],
  ['strengthsQuestion', "Appraisee's Strengths"],
  ['improvementInstruction', 'Areas of Improvement'],
];

function cloneAnswers(source = {}) {
  return {
    ...emptyAnswers,
    ...source,
    achievedGoals: source.achievedGoals?.length ? source.achievedGoals : emptyAnswers.achievedGoals,
    performanceOutputs: source.performanceOutputs?.length ? source.performanceOutputs : emptyAnswers.performanceOutputs,
    improvementPlans: source.improvementPlans?.length ? source.improvementPlans : emptyAnswers.improvementPlans,
    confirmations: { ...emptyAnswers.confirmations, ...(source.confirmations || {}) },
    careerDevelopment: {
      ...emptyAnswers.careerDevelopment,
      ...(source.careerDevelopment || {}),
      actionPlans: source.careerDevelopment?.actionPlans?.length ? source.careerDevelopment.actionPlans : emptyAnswers.careerDevelopment.actionPlans,
    },
  };
}

function ratingValue(code) {
  return outputRatings.find((item) => item.code === code)?.value || 0;
}

function performanceLevel(score) {
  if (score === null || Number.isNaN(score)) return '';
  if (score >= 4.51) return 'Exceptional';
  if (score >= 3.76) return 'Exceeds Expectations';
  if (score >= 3.01) return 'Meets Expectations';
  if (score >= 2.26) return 'Meets Most Expectations';
  return 'Does Not Meet Expectations';
}

function normalizeQuestionnaireCategories(payload = {}) {
  const categories = Array.isArray(payload.categories) ? payload.categories : [];
  return categories.map((category, index) => ({
    id: String(category.id ?? `category-${index}`),
    title: category.title || category.factor_name || `Category ${index + 1}`,
    description: category.description || category.factor_description || '',
    weight: Number(category.factor_weight ?? category.weight ?? 0),
    questions: (Array.isArray(category.questions) ? category.questions : []).map((question, questionIndex) => ({
      id: String(question.id ?? question.sourceId ?? `${category.id || index}-${questionIndex}`),
      text: question.text || question.question_text || '',
    })).filter((question) => question.text.trim() !== ''),
  })).filter((category) => category.questions.length > 0);
}

function buildSelfEvaluationPayloadFrom(categories, currentAnswers) {
  return {
    categories: categories.map((category) => {
      const answersForCategory = {};
      category.questions.forEach((question) => {
        answersForCategory[question.id] = Number(currentAnswers.selfRatings?.[question.id] || 0);
      });
      return {
        id: category.id,
        title: category.title,
        factor_weight: Number(category.weight || 0),
        answers: answersForCategory,
        evidence: currentAnswers.selfEvidence?.[category.id] || '',
      };
    }),
  };
}

function readSignatureFile(file) {
  return new Promise((resolve, reject) => {
    if (!file?.type?.startsWith('image/')) {
      reject(new Error('Please upload an image file for the signature.'));
      return;
    }

    if (file.size > 5 * 1024 * 1024) {
      reject(new Error('Signature image must be 5MB or smaller.'));
      return;
    }

    const reader = new FileReader();
    reader.onerror = () => reject(new Error('Unable to read the signature image.'));
    reader.onload = () => {
      const image = new Image();
      image.onerror = () => reject(new Error('Unable to load the signature image.'));
      image.onload = () => {
        const maxWidth = 720;
        const maxHeight = 240;
        const scale = Math.min(1, maxWidth / image.width, maxHeight / image.height);
        const width = Math.max(1, Math.round(image.width * scale));
        const height = Math.max(1, Math.round(image.height * scale));
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        context.drawImage(image, 0, 0, width, height);
        resolve({
          dataUrl: canvas.toDataURL('image/jpeg', 0.82),
          name: file.name,
        });
      };
      image.src = String(reader.result || '');
    };
    reader.readAsDataURL(file);
  });
}

function roleParam(roleKey) {
  if (roleKey === 'programHead') return 'program_head';
  if (roleKey === 'admin') return 'faculty';
  return roleKey || 'faculty';
}

const adminRoleOptions = [
  { value: 'faculty', label: 'Faculty Self Evaluation' },
  { value: 'dean', label: 'Administrative Self Evaluation' },
  { value: 'vpaa', label: 'VPAA Self Evaluation' },
  { value: 'program_head', label: 'Program Head Self Evaluation' },
];

export default function SelfEvaluationModule({ role, initialTargetRole = null, targetRoleOptions = adminRoleOptions, displayMode = 'manage', assignmentId = null, managedRecordId = null, onSubmitted = null, pendingEvaluations = [], onEvaluateNext = null, onFinish = null, templateOverride = null, onTemplateChange = null }) {
  const [searchParams] = useSearchParams();
  const { selectedPeriodId, selectedPeriod } = useEvaluationPeriod();
  const isAdmin = role?.key === 'admin';
  const routeAssignmentId = assignmentId ?? searchParams.get('assignment_id') ?? null;
  const managedRecordIdNumber = Number(managedRecordId || 0);
  const [targetRole, setTargetRole] = useState(initialTargetRole || roleParam(role?.key));
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [employee, setEmployee] = useState({ name: role?.user?.name || '', positionTitle: '', department: role?.user?.department || '', appraisalPeriod: '' });
  const [selfAssignment, setSelfAssignment] = useState(null);
  const [recordMeta, setRecordMeta] = useState(null);
  const [assignmentMissing, setAssignmentMissing] = useState(false);
  const [templateInfo, setTemplateInfo] = useState({ title: '', template: {}, definition: { schemaVersion: 2, scales: [], sections: [] }, revision: 1 });
  const [answers, setAnswers] = useState(cloneAnswers());
  const [records, setRecords] = useState([]);
  const [status, setStatus] = useState('draft');
  const [loadError, setLoadError] = useState('');
  const [formCategories, setFormCategories] = useState([]);
  const [categoryLoadError, setCategoryLoadError] = useState('');
  const [managedMode, setManagedMode] = useState(false);
  const [managedPermissions, setManagedPermissions] = useState({});
  const [activeSectionIndex, setActiveSectionIndex] = useState(0);
  const [validationErrors, setValidationErrors] = useState({});
  const [showValidation, setShowValidation] = useState(false);
  const [successMessage, setSuccessMessage] = useState('');
  const [questionnaireUpdated, setQuestionnaireUpdated] = useState(false);
  const [goalsGate, setGoalsGate] = useState({ loading: true, approved: false, record: null });
  const hydratedRef = useRef(false);
  const dirtyRef = useRef(false);
  const dirtyVersionRef = useRef(0);
  const saveInFlightRef = useRef(false);
  const loadRequestRef = useRef(0);
  const latestDraftRef = useRef({
    answers: cloneAnswers(),
    employee: { name: role?.user?.name || '', positionTitle: '', department: role?.user?.department || '', appraisalPeriod: '' },
    selfAssignmentId: null,
    routeAssignmentId,
    targetRole: initialTargetRole || roleParam(role?.key),
    categories: [],
    questionnaireRevision: 1,
    questionnaireSnapshot: null,
    canAutoSave: false,
  });

  useEffect(() => {
    if (displayMode === 'preview' && templateOverride?.definition) {
      setTemplateInfo(templateOverride);
    }
  }, [displayMode, templateOverride]);

  useEffect(() => {
    if (isAdmin && displayMode !== 'preview' && !loading && templateInfo?.definition) {
      onTemplateChange?.(templateInfo);
    }
  }, [displayMode, isAdmin, loading, onTemplateChange, templateInfo]);

  const managedReviewerRole = roleParam(role?.key);
  const isManagedReadOnly = displayMode === 'managed_view';
  const isManagedReviewerEdit = managedMode
    && ['dean', 'program_head'].includes(managedReviewerRole)
    && (managedReviewerRole === 'dean' ? ['faculty', 'program_head'].includes(targetRole) : targetRole === 'faculty')
    && managedRecordIdNumber > 0;
  const effectiveRole = isAdmin || isManagedReviewerEdit ? targetRole : role?.key;
  const canEdit = !isManagedReadOnly && ((!isAdmin && status !== 'submitted') || (isManagedReviewerEdit && managedPermissions?.canEditSubmitted === true));
  // Part V belongs to the official PMAS form and must remain visible in the
  // employee/user form preview for every supported account role.
  const showCareer = true;
  const t = templateInfo.template || {};

  const computed = useMemo(() => {
    const totalWeight = answers.performanceOutputs.reduce((sum, row) => sum + (Number(row.weight) || 0), 0);
    const weighted = answers.performanceOutputs.reduce((sum, row) => {
      const weight = Number(row.weight) || 0;
      const score = ratingValue(row.rating);
      return sum + (weight > 0 && score > 0 ? (weight / 100) * score : 0);
    }, 0);
    const outputs = totalWeight > 0 && Math.abs(totalWeight - 100) > 0.001 ? weighted / (totalWeight / 100) : weighted;
    const scoringCategories = formCategories.length > 0 ? formCategories : [{
      id: 'self-evaluation',
      title: 'Self Evaluation',
      description: 'Rate your own performance based on the PMAS criteria and provide behavioral evidence where required.',
      weight: 100,
      questions: [
        { id: 'self-overall-output', text: t.question4 || 'How would you evaluate your overall performance considering performance outputs and work behaviors during this period in review?' },
        { id: 'self-strengths', text: t.question3 || 'What personal strengths contributed to your performance level during the appraisal period?' },
        { id: 'self-contribution', text: t.question5 || 'How can you further contribute your talents, knowledge, and skills to the organization?' },
      ],
    }];
    const answeredCategoryScores = scoringCategories.map((category) => {
      const ratingsForCategory = category.questions
        .map((question) => Number(answers.selfRatings?.[question.id] || 0))
        .filter((rating) => rating >= 1 && rating <= 5);
      if (ratingsForCategory.length !== category.questions.length || ratingsForCategory.length === 0) return null;
      const average = ratingsForCategory.reduce((sum, rating) => sum + rating, 0) / ratingsForCategory.length;
      return { average, weight: Number(category.weight || 0) };
    }).filter(Boolean);
    const categoryWeightTotal = answeredCategoryScores.reduce((sum, item) => sum + item.weight, 0);
    const categoryWeighted = answeredCategoryScores.reduce((sum, item) => sum + (item.average * (item.weight / 100)), 0);
    const selfFactorsScore = answeredCategoryScores.length === scoringCategories.length && scoringCategories.length > 0
      ? (categoryWeightTotal > 0 ? categoryWeighted / (categoryWeightTotal / 100) : answeredCategoryScores.reduce((sum, item) => sum + item.average, 0) / answeredCategoryScores.length)
      : null;
    const manualFactors = answers.performanceFactorsScore === '' ? null : Number(answers.performanceFactorsScore);
    const factors = selfFactorsScore ?? manualFactors;
    const overall = factors === null || Number.isNaN(factors) ? null : (outputs * 0.7) + (factors * 0.3);
    return {
      outputs: Number(outputs.toFixed(4)),
      factors,
      selfFactorsScore: selfFactorsScore === null ? null : Number(selfFactorsScore.toFixed(4)),
      overall: overall === null ? null : Number(overall.toFixed(4)),
      level: performanceLevel(overall),
      totalWeight,
    };
  }, [answers.performanceFactorsScore, answers.performanceOutputs, answers.selfRatings, formCategories, t.question3, t.question4, t.question5]);

  const selfEvaluationCategories = useMemo(() => formCategories, [formCategories]);

  useEffect(() => {
    latestDraftRef.current = {
      answers,
      employee,
      selfAssignmentId: selfAssignment?.id || null,
      routeAssignmentId,
      targetRole,
      categories: selfEvaluationCategories,
      questionnaireRevision: templateInfo.revision || 1,
      questionnaireSnapshot: templateInfo.definition || null,
      canAutoSave: !isAdmin && !isManagedReviewerEdit && canEdit && hydratedRef.current && !loading,
    };
  }, [answers, canEdit, employee, isAdmin, isManagedReviewerEdit, loading, routeAssignmentId, selfAssignment?.id, selfEvaluationCategories, targetRole, templateInfo.definition, templateInfo.revision]);

  const modernSections = useMemo(() => (templateInfo.definition?.sections || [])
    .filter((section) => section.visible !== false && (section.type !== 'career' || showCareer))
    .map((section) => ({ ...section, title: section.title || 'Untitled Section' })), [showCareer, templateInfo.definition]);

  const activeSection = modernSections[Math.min(activeSectionIndex, Math.max(0, modernSections.length - 1))] || modernSections[0];
  const sectionCompletionPercent = modernSections.length > 1
    ? Math.round((activeSectionIndex / (modernSections.length - 1)) * 100)
    : 0;

  function categoryStats(category) {
    const ratingsForCategory = category.questions.map((question) => Number(answers.selfRatings?.[question.id] || 0));
    const answered = ratingsForCategory.filter((rating) => rating >= 1 && rating <= 5).length;
    const total = ratingsForCategory.reduce((sum, rating) => sum + (rating >= 1 && rating <= 5 ? rating : 0), 0);
    const average = answered === category.questions.length && answered > 0 ? total / answered : 0;
    const evidence = (answers.selfEvidence?.[category.id] || '').trim();
    const requiresEvidence = answered === category.questions.length && answered > 0 && (average >= 4.51 || average <= 3);
    return { answered, totalQuestions: category.questions.length, average, evidence, requiresEvidence };
  }

  function validateModernSection(section = activeSection) {
    const errors = {};
    if (!section) return errors;
    if (section.type === 'questions') {
      (section.questions || []).forEach((question) => {
        const value = answers.dynamicResponses?.[question.id] ?? answers.selfRatings?.[question.id] ?? '';
        if (question.required && String(value).trim() === '') errors[`dynamic:${question.id}`] = 'Complete all required questionnaire items.';
        if (question.evidenceEnabled && question.evidenceRequired && !String(answers.dynamicResponses?.[`${question.id}__evidence`] || '').trim()) errors[`dynamic:${question.id}:evidence`] = 'Complete the required behavioral evidence field.';
        if (question.commentsEnabled && question.commentsRequired && !String(answers.dynamicResponses?.[`${question.id}__comment`] || '').trim()) errors[`dynamic:${question.id}:comment`] = 'Complete the required comments field.';
      });
    }
    if (section.type === 'category') {
      section.category.questions.forEach((question) => {
        const rating = Number(answers.selfRatings?.[question.id] || 0);
        if (rating < 1 || rating > 5) {
          errors[`rating:${question.id}`] = 'This indicator needs a self-rating.';
        }
      });
      const stats = categoryStats(section.category);
      if (stats.requiresEvidence && !stats.evidence) {
        errors[`evidence:${section.category.id}`] = 'Behavioral evidence is required for this category rating.';
      }
    }
    if (section.type === 'outputs') {
      if (!answers.performanceOutputs.some((row) => row.goals.trim() && Number(row.weight) > 0 && row.rating)) {
        errors.performanceOutputs = 'Add at least one performance output with a goal, weight, and rating.';
      } else if (Math.abs(computed.totalWeight - 100) > 0.001) {
        const difference = Math.abs(100 - computed.totalWeight);
        errors.performanceOutputs = computed.totalWeight < 100
          ? `Performance Output weights must total 100% before proceeding. Add ${difference.toFixed(2).replace(/\.?0+$/, '')}% more.`
          : `Performance Output weights must total 100% before proceeding. Reduce the total by ${difference.toFixed(2).replace(/\.?0+$/, '')}%.`;
      }
    }
    if (section.type === 'partI') {
      if (!answers.achievedGoals.some((row) => row.goals.trim() || row.accomplishment.trim())) {
        errors.achievedGoals = 'Add at least one achieved goal or accomplishment.';
      }
      if (!answers.overallSelfRating) {
        errors.overallSelfRating = 'Please select your overall self-evaluation rating.';
      }
      if (!answers.ratingBasis.trim()) {
        errors.ratingBasis = 'Please explain the basis for your self-evaluation rating.';
      }
    }
    const approvalRequirements = templateInfo.definition?.approvalRequirements || {};
    if (section.type === 'confirmation' && !answers.confirmations.appraisee.trim()) {
      errors.confirmation = 'Typed name confirmation for the appraisee is required.';
    }
    if (section.type === 'confirmation' && approvalRequirements.requireEmployeeSignature !== false && !answers.confirmations.appraiseeSignature) {
      errors.appraiseeSignature = 'Upload the appraisee virtual signature.';
    }
    if (section.type === 'confirmation' && approvalRequirements.requireReviewerComments && !String(answers.comments || '').trim()) {
      errors.reviewerComments = 'Comments are required before this evaluation can be submitted for review.';
    }
    return errors;
  }

  function validateAllModernSections() {
    return modernSections.reduce((allErrors, section) => ({ ...allErrors, ...validateModernSection(section) }), {});
  }

  function showSectionValidationErrors(section = activeSection) {
    const errors = validateModernSection(section);
    if (Object.keys(errors).length > 0) {
      setValidationErrors(errors);
      setShowValidation(true);
      addToast({ type: 'error', text: Object.values(errors)[0] });
      return false;
    }
    return true;
  }

  function goToSection(index) {
    const nextIndex = Math.max(0, Math.min(index, modernSections.length - 1));
    if (nextIndex > activeSectionIndex) {
      for (let sectionIndex = activeSectionIndex; sectionIndex < nextIndex; sectionIndex += 1) {
        const section = modernSections[sectionIndex];
        if (!showSectionValidationErrors(section)) {
          setActiveSectionIndex(sectionIndex);
          return;
        }
      }
    }
    setShowValidation(false);
    setValidationErrors({});
    setActiveSectionIndex(nextIndex);
  }

  function goNext() {
    if (!showSectionValidationErrors(activeSection)) {
      return;
    }
    goToSection(activeSectionIndex + 1);
  }

  function goPrevious() {
    goToSection(activeSectionIndex - 1);
  }

  const load = useCallback(async (nextRole = targetRole) => {
    const requestId = ++loadRequestRef.current;
    setLoading(true);
    setLoadError('');
    if (isAdmin) setRecords([]);
    try {
      const params = new URLSearchParams({ role: nextRole });
      if (routeAssignmentId) params.set('assignment_id', String(routeAssignmentId));
      if (managedRecordIdNumber > 0) params.set('record_id', String(managedRecordIdNumber));
      if (isAdmin && selectedPeriodId) params.set('period_id', String(selectedPeriodId));
      const payload = await apiFetch(`/api/self-evaluations.php?${params.toString()}`);
      if (requestId !== loadRequestRef.current) return;
      const loadedTemplate = payload.template || { title: '', template: {}, definition: { schemaVersion: 2, scales: [], sections: [] }, revision: 1 };
      setTemplateInfo(loadedTemplate);
      setFormCategories((loadedTemplate.definition?.sections || []).filter((section) => section.visible !== false && section.type === 'questions').map((section) => ({
        id: section.id, title: section.title, description: section.instructions || '', weight: Number(section.weight || 0),
        questions: (section.questions || []).filter((question) => question.type === 'rating'),
      })).filter((section) => section.questions.length > 0));
      setAssignmentMissing(false);
      if (payload.mode === 'admin') {
        setManagedMode(false);
        setManagedPermissions({});
        setRecordMeta(null);
        setRecords(payload.records || []);
        setAnswers(cloneAnswers());
      } else {
        setManagedMode(payload.mode === 'managed');
        setManagedPermissions(payload.permissions || {});
        setSelfAssignment(payload.assignment || null);
        setRecordMeta(payload.record || null);
        const loadedEmployee = payload.employee || { name: role?.user?.name || '', department: role?.user?.department || '' };
        const loadedAnswers = cloneAnswers(payload.record?.answers_json || {});
        if (payload.record?.status === 'submitted' && payload.record?.questionnaire_snapshot) {
          const historicalDefinition = payload.record.questionnaire_snapshot;
          setTemplateInfo((prev) => ({ ...prev, definition: historicalDefinition, revision: Number(payload.record.questionnaire_revision || prev.revision || 1) }));
          setFormCategories((historicalDefinition.sections || []).filter((section) => section.visible !== false && section.type === 'questions').map((section) => ({ id: section.id, title: section.title, description: section.instructions || '', weight: Number(section.weight || 0), questions: (section.questions || []).filter((question) => question.type === 'rating') })).filter((section) => section.questions.length > 0));
        }
        setQuestionnaireUpdated(Boolean(payload.record && payload.record.status !== 'submitted' && Number(payload.record.questionnaire_revision || 0) > 0 && Number(payload.record.questionnaire_revision) < Number(loadedTemplate.revision || 1)));
        setEmployee(loadedEmployee);
        setAnswers({
          ...loadedAnswers,
          confirmations: {
            ...loadedAnswers.confirmations,
            appraisee: loadedAnswers.confirmations.appraisee || loadedEmployee?.name || role?.user?.name || '',
          },
        });
        setStatus(payload.record?.status || 'draft');
        dirtyRef.current = false;
        dirtyVersionRef.current = 0;
        hydratedRef.current = true;
      }
    } catch (error) {
      if (requestId !== loadRequestRef.current) return;
      const message = error.message || 'Unable to load self evaluation.';
      setLoadError(message);
      setAssignmentMissing(message.toLowerCase().includes('assignment'));
      setSelfAssignment(null);
      setRecordMeta(null);
      setManagedMode(false);
      setManagedPermissions({});
      addToast({ type: 'error', text: message });
    } finally {
      if (requestId === loadRequestRef.current) setLoading(false);
    }
  }, [isAdmin, managedRecordIdNumber, routeAssignmentId, selectedPeriodId, targetRole]);

  useEffect(() => {
    load(targetRole);
  }, [load, targetRole]);

  useEffect(() => {
    if (initialTargetRole) {
      setTargetRole(initialTargetRole);
    }
  }, [initialTargetRole]);

  useEffect(() => { setCategoryLoadError(''); }, [targetRole]);

  const saveDraftSilently = useCallback(async ({ force = false, keepalive = false } = {}) => {
    const latest = latestDraftRef.current;
    const assignmentId = latest.selfAssignmentId || latest.routeAssignmentId;
    if (!latest.canAutoSave || !assignmentId || (saveInFlightRef.current && !keepalive) || (!force && !dirtyRef.current)) {
      return false;
    }

    const saveVersion = dirtyVersionRef.current;
    const formPayload = buildSelfEvaluationPayloadFrom(latest.categories, latest.answers);
    const body = JSON.stringify({
      action: 'save_draft',
      role: latest.targetRole,
      assignment_id: assignmentId,
      employee: latest.employee,
      answers: latest.answers,
      form_payload: formPayload,
      form_b_payload: formPayload,
      questionnaire_revision: latest.questionnaireRevision,
      questionnaire_snapshot: latest.questionnaireSnapshot,
    });

    saveInFlightRef.current = true;
    try {
      if (keepalive) {
        const response = await fetch(apiUrl('/api/self-evaluations.php'), {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          keepalive: true,
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          body,
        });
        if (!response.ok) throw new Error('Draft save failed.');
      } else {
        await apiFetch('/api/self-evaluations.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body,
        });
      }
      if (dirtyVersionRef.current === saveVersion) {
        dirtyRef.current = false;
      }
      return true;
    } catch {
      return false;
    } finally {
      saveInFlightRef.current = false;
    }
  }, []);

  useEffect(() => {
    if (isAdmin || isManagedReviewerEdit || !canEdit || !hydratedRef.current || loading || saving || !dirtyRef.current) return undefined;
    const timer = window.setTimeout(() => {
      saveDraftSilently();
    }, 1200);
    return () => window.clearTimeout(timer);
  }, [answers, canEdit, employee, isAdmin, isManagedReviewerEdit, loading, saveDraftSilently, saving]);

  useEffect(() => {
    const flushDraft = () => {
      if (dirtyRef.current) {
        saveDraftSilently({ force: true, keepalive: true });
      }
    };
    const flushWhenHidden = () => {
      if (document.visibilityState === 'hidden') flushDraft();
    };

    window.addEventListener('blur', flushDraft);
    window.addEventListener('pagehide', flushDraft);
    document.addEventListener('visibilitychange', flushWhenHidden);
    return () => {
      flushDraft();
      window.removeEventListener('blur', flushDraft);
      window.removeEventListener('pagehide', flushDraft);
      document.removeEventListener('visibilitychange', flushWhenHidden);
    };
  }, [saveDraftSilently]);

  useEffect(() => {
    if (isAdmin || isManagedReviewerEdit || loading) {
      if (isAdmin || isManagedReviewerEdit) setGoalsGate({ loading: false, approved: true, record: null });
      return;
    }
    let active = true;
    apiFetch('/api/goals-records.php?mode=mine')
      .then((payload) => {
        if (!active) return;
        const approved = payload.record?.status === 'approved';
        setGoalsGate({ loading: false, approved, record: payload.record || null });
        if (approved && payload.record?.goals?.length) {
          setAnswers((current) => {
            const existing = current.achievedGoals || [];
            const existingOutputs = current.performanceOutputs || [];
            const transferred = payload.record.goals.map((goal, index) => ({
              goals: `${goal.keyResultArea}${goal.goalStatement ? ` — ${goal.goalStatement}` : ''}`,
              accomplishment: existing[index]?.accomplishment || '',
              approvedGoal: true,
            }));
            const performanceOutputs = payload.record.goals.map((goal, index) => ({
              goals: `${goal.keyResultArea}${goal.goalStatement ? ` — ${goal.goalStatement}` : ''}`,
              weight: goal.weight,
              accomplishment: existingOutputs[index]?.accomplishment || '',
              rating: existingOutputs[index]?.rating || '',
              approvedGoal: true,
            }));
            return { ...current, achievedGoals: transferred, performanceOutputs };
          });
        }
      })
      .catch(() => active && setGoalsGate({ loading: false, approved: false, record: null }));
    return () => { active = false; };
  }, [isAdmin, isManagedReviewerEdit, loading]);

  function markDraftDirty() {
    dirtyRef.current = true;
    dirtyVersionRef.current += 1;
  }

  function updateAnswer(name, value) {
    markDraftDirty();
    setAnswers((prev) => ({ ...prev, [name]: value }));
  }

  function updateDynamicResponse(questionId, value) {
    markDraftDirty();
    const question = (templateInfo.definition?.sections || []).flatMap((section) => section.questions || []).find((item) => item.id === questionId);
    setAnswers((prev) => ({
      ...prev,
      dynamicResponses: { ...(prev.dynamicResponses || {}), [questionId]: value },
      selfRatings: question?.type === 'rating' ? { ...(prev.selfRatings || {}), [questionId]: Number(value) } : prev.selfRatings,
    }));
  }

  function updateRow(section, index, name, value) {
    markDraftDirty();
    setAnswers((prev) => ({
      ...prev,
      [section]: prev[section].map((row, rowIndex) => rowIndex === index ? { ...row, [name]: value } : row),
    }));
  }

  function addRow(section, row) {
    markDraftDirty();
    setAnswers((prev) => ({ ...prev, [section]: [...prev[section], row] }));
  }

  function removeRow(section, index) {
    markDraftDirty();
    setAnswers((prev) => ({ ...prev, [section]: prev[section].filter((_, rowIndex) => rowIndex !== index) }));
  }

  function updateCareer(index, name, value) {
    markDraftDirty();
    setAnswers((prev) => ({
      ...prev,
      careerDevelopment: {
        ...prev.careerDevelopment,
        actionPlans: prev.careerDevelopment.actionPlans.map((row, rowIndex) => rowIndex === index ? { ...row, [name]: value } : row),
      },
    }));
  }

  function validationMessage() {
    const modernErrors = validateAllModernSections();
    if (Object.keys(modernErrors).length > 0) return Object.values(modernErrors)[0];
    if (!answers.performanceOutputs.some((row) => row.goals.trim() && Number(row.weight) > 0 && row.rating)) return 'Add at least one performance output with weight and rating.';
    if (computed.factors !== null && (computed.factors < 1 || computed.factors > 5)) return 'Performance Factors score must be between 1 and 5.';
    if (!answers.confirmations.appraisee.trim()) return 'Typed name confirmation for the appraisee is required.';
    if (!answers.confirmations.appraiseeSignature) return 'Upload the appraisee virtual signature.';
    return '';
  }

  function buildSelfEvaluationPayload() {
    return buildSelfEvaluationPayloadFrom(selfEvaluationCategories, answers);
  }

  async function save(action, options = {}) {
    if (isAdmin) return;
    const isManagedUpdate = isManagedReviewerEdit && action === 'submit';
    if (isManagedUpdate && managedRecordIdNumber <= 0) {
      addToast({ type: 'error', text: 'Unable to identify the faculty self-evaluation record to update.' });
      return;
    }
    if (!selfAssignment?.id && action === 'submit') {
      setAssignmentMissing(true);
      addToast({ type: 'error', text: 'Create or load your self-evaluation assignment before submitting.' });
      return;
    }
    if (action === 'submit') {
      const message = validationMessage();
      if (message) {
        addToast({ type: 'error', text: message });
        setValidationErrors(validateAllModernSections());
        setShowValidation(true);
        return;
      }
      if (!options.confirmed) {
        const confirmed = isManagedUpdate
          ? await confirmProceed({
              title: 'Update submitted faculty self-evaluation?',
              message: `This will overwrite the submitted self-evaluation for ${employee.name || 'this faculty member'} and record the ${managedReviewerRole === 'program_head' ? 'Program Head' : 'Dean'} update in the activity logs.`,
              confirmText: 'Update',
            })
          : await confirmSubmitEvaluation();
        if (!confirmed) return;
      }
    }
    setSaving(true);
    try {
      const requestAction = isManagedUpdate ? 'reviewer_update' : action;
      const payload = await apiFetch('/api/self-evaluations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: requestAction,
          role: targetRole,
          assignment_id: selfAssignment?.id || routeAssignmentId,
          record_id: isManagedUpdate ? managedRecordIdNumber : undefined,
          status: isManagedUpdate ? 'submitted' : undefined,
          employee,
          answers,
          form_payload: buildSelfEvaluationPayload(),
          form_b_payload: buildSelfEvaluationPayload(),
          questionnaire_revision: templateInfo.revision || 1,
          questionnaire_snapshot: templateInfo.definition || null,
        }),
      });
      setStatus(payload.status || (action === 'submit' ? 'submitted' : 'draft'));
      dirtyRef.current = false;
      dirtyVersionRef.current = 0;
      addToast({ type: 'success', text: payload.message || 'Self evaluation saved.' });
      if (action === 'submit') {
        setSuccessMessage(payload.message || (isManagedUpdate ? 'Faculty self evaluation updated successfully.' : 'Self evaluation submitted successfully.'));
        onSubmitted?.(payload);
      }
    } catch (error) {
      addToast({ type: 'error', text: error.message || 'Unable to save self evaluation.' });
    } finally {
      setSaving(false);
    }
  }

  async function initializeAssignment() {
    if (isAdmin) return;
    setSaving(true);
    try {
      const payload = await apiFetch('/api/self-evaluations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'init_assignment', role: targetRole }),
      });
      setSelfAssignment(payload.assignment || null);
      setAssignmentMissing(false);
      setLoadError('');
      addToast({ type: 'success', text: payload.message || 'Self-evaluation assignment is ready.' });
      await load(targetRole);
    } catch (error) {
      setAssignmentMissing(true);
      addToast({ type: 'error', text: error.message || 'Unable to create self-evaluation assignment.' });
    } finally {
      setSaving(false);
    }
  }

  async function saveTemplate() {
    const confirmed = await confirmSaveChanges();
    if (!confirmed) return;
    setSaving(true);
    try {
      const payload = await apiFetch('/api/self-evaluations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_template', target_role: targetRole, title: templateInfo.title, definition: templateInfo.definition, expected_revision: templateInfo.revision }),
      });
      setTemplateInfo(payload.template);
      addToast({ type: 'success', text: payload.message || 'Template saved.' });
    } catch (error) {
      addToast({ type: 'error', text: error.message || 'Unable to save template.' });
    } finally {
      setSaving(false);
    }
  }

  async function reopen(recordId) {
    const confirmed = await confirmProceed({
      title: 'Are you sure you want to reopen this self evaluation?',
      message: 'This will allow the submitted record to be edited again by the authorized user.',
      confirmText: 'Reopen',
    });
    if (!confirmed) return;
    try {
      await apiFetch('/api/self-evaluations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reopen', record_id: recordId }),
      });
      addToast({ type: 'success', text: 'Self evaluation reopened.' });
      await load(targetRole);
    } catch (error) {
      addToast({ type: 'error', text: error.message || 'Unable to reopen record.' });
    }
  }

  const formCode = effectiveRole === 'faculty' ? 'PMAS FORM 3b' : 'PMAS FORM 3a';
  const audienceLabel = effectiveRole === 'faculty'
    ? 'Faculty'
    : effectiveRole === 'vpaa'
    ? 'VPAA'
    : effectiveRole === 'dean'
    ? 'Administrative'
    : 'Program Head';
  const paperSectionTypes = ['partI', 'questions', 'category', 'outputs', 'summary', 'confirmation', 'career'];
  const isPaperSection = paperSectionTypes.includes(activeSection?.type);
  const submitBlocker = !canEdit
    ? status === 'submitted'
      ? isManagedReviewerEdit
        ? 'You do not have permission to edit this submitted faculty self-evaluation.'
        : 'This self-evaluation has already been submitted.'
      : 'This self-evaluation is view-only.'
    : !selfAssignment?.id
      ? 'No self-evaluation assignment is ready for this appraisal period.'
      : validationMessage();
  const canSubmitSelfEvaluation = canEdit && !!selfAssignment?.id && !submitBlocker && !saving;
  const answeredQuestionCount = selfEvaluationCategories.reduce((sum, category) => sum + categoryStats(category).answered, 0);
  const totalQuestionCount = selfEvaluationCategories.reduce((sum, category) => sum + category.questions.length, 0);
  const showSubmittedPreview = status === 'submitted' && (!isManagedReviewerEdit || isManagedReadOnly);

  if (successMessage && !isManagedReviewerEdit) {
    const pending = pendingEvaluations.filter((item) => item.status !== 'submitted' && item.status !== 'overdue');
    return (
      <section className="admin-box module-wide self-evaluation-page self-eval-modern-page page-enter evaluation-form-modal">
        <div className={`evaluation-submit-success ${pending.length ? 'has-next' : 'all-done'}`}>
          <div className="evaluation-confetti" aria-hidden="true">
            {Array.from({ length: pending.length ? 42 : 56 }, (_, piece) => (
              <span key={piece} style={{ '--piece': piece }} />
            ))}
          </div>
          <div className="evaluation-success-hero">
            <div className="evaluation-success-icon"><PartyPopper size={28} /></div>
            <div>
              <span>Congratulations</span>
              <h3>Your self-evaluation was submitted successfully.</h3>
              <p>{pending.length ? 'Your responses have been recorded. Continue with your next assigned evaluation.' : 'You have completed all currently assigned evaluations. Thank you!'}</p>
            </div>
          </div>
          {pending.length ? (
            <div className="evaluation-next-step">
              <div className="evaluation-next-head"><div><strong>Proceed to the next evaluation</strong></div><span>{pending.length} remaining</span></div>
              <div className="evaluation-next-grid">
                {pending.map((item) => {
                  const name = item.fullName || item.evaluateeName || 'Assigned Employee';
                  return (
                    <button key={item.id} type="button" className="evaluation-next-card" onClick={() => onEvaluateNext?.(item)}>
                      <span className="evaluation-next-avatar">{name.charAt(0).toUpperCase()}</span>
                      <span className="evaluation-next-copy"><strong>{name}</strong><small>{item.position || item.evaluateePosition || 'Assigned evaluation'}</small></span>
                      <ArrowRight size={16} />
                    </button>
                  );
                })}
              </div>
            </div>
          ) : <div className="evaluation-complete-panel"><CheckCircle2 size={20} /><div><strong>All evaluations complete</strong><span>You may return to your dashboard.</span></div></div>}
          <div className="evaluation-success-actions"><button type="button" className="dipascaf-evaluate-btn evaluation-nav-btn secondary" onClick={onFinish}>{pending.length ? 'Back to Dashboard' : 'Close'}</button></div>
        </div>
      </section>
    );
  }

  if (loading) {
    return (
      <section className="admin-box module-wide self-evaluation-page self-eval-loading-shell page-enter">
        <div className="empty-state">Loading self evaluation form...</div>
      </section>
    );
  }

  if (!isAdmin && !isManagedReviewerEdit && !goalsGate.loading && !goalsGate.approved) {
    return (
      <section className="admin-box module-wide self-evaluation-page page-enter">
        <div className="notice warning self-eval-load-warning">
          <div>
            <strong>Goals Record Sheet Required</strong>
            <p>You must complete, submit, and obtain approval for your Goals Record Sheet before proceeding with your Self-Evaluation.</p>
          </div>
        </div>
      </section>
    );
  }

  if (isAdmin && displayMode === 'preview') {
    return (
      <section className="self-evaluation-page self-eval-preview-only">
        <div className="dynamic-preview-heading">
          <span>{formCode}</span>
          <div>
            <h2>{templateInfo.title}</h2>
            <p>{templateInfo.definition?.description}</p>
          </div>
          <b>Revision {templateInfo.revision || 1}</b>
        </div>
        <AdminSelfEvaluationTablePreview
          title={templateInfo.title}
          template={t}
          definition={templateInfo.definition}
          formCode={formCode}
          audienceLabel={audienceLabel}
        />
      </section>
    );
  }

  if (isAdmin && displayMode !== 'preview') {
    return (
      <section className="admin-box module-wide self-evaluation-page page-enter">
        <div className="box-title self-eval-title">
          <div>
            <h2>PMAS Self Evaluation Questionnaires</h2>
            <span>Manage Form B and Form A self evaluation wording without changing the layout.</span>
          </div>
          <div className="self-eval-period-toolbar">
            <div className="self-eval-current-period">
              <CalendarRange size={18} />
              <span>Current Evaluation Period<strong>{selectedPeriod?.period_name || 'Select a period'}</strong></span>
            </div>
            <PeriodSelector compact />
            <select value={targetRole} onChange={(event) => setTargetRole(event.target.value)}>
              {targetRoleOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </div>
        </div>
        <div className="self-questionnaire-workspace">
          <div className="self-questionnaire-builder-pane">
            <div className="self-questionnaire-pane-label"><span>Questionnaire Management · Version {templateInfo.revision || 1}</span><strong>{targetRole === 'faculty' ? 'Faculty Self-Evaluation Form Builder' : 'Leadership Self-Evaluation Form Builder'}</strong><p>Create, arrange, preview, and publish the exact form used for this audience.</p></div>
            <DynamicQuestionnaireBuilder
              title={templateInfo.title || ''}
              definition={templateInfo.definition || { schemaVersion: 2, scales: [], sections: [] }}
              revision={templateInfo.revision || 1}
              saving={saving}
              onTitleChange={(title) => setTemplateInfo((prev) => ({ ...prev, title }))}
              onChange={(definition) => setTemplateInfo((prev) => ({ ...prev, definition }))}
              onSave={saveTemplate}
            />
          </div>
          <aside className="self-questionnaire-preview-pane">
            <div className="self-questionnaire-preview-head"><div><span>Live employee preview</span><strong>{templateInfo.title}</strong></div><b>Revision {templateInfo.revision || 1}</b></div>
            <div className="self-questionnaire-preview-scroll">
              <AdminSelfEvaluationTablePreview
                title={templateInfo.title}
                template={t}
                definition={templateInfo.definition}
                formCode={formCode}
                audienceLabel={audienceLabel}
              />
            </div>
          </aside>
        </div>
        <div className="self-eval-section">
          <div className="self-eval-records-heading">
            <div><h3>Submitted Self Evaluations</h3><p>Showing records only for <strong>{selectedPeriod?.period_name || 'the selected evaluation period'}</strong>.</p></div>
            <span><CalendarRange size={15} /> {selectedPeriod?.school_year || selectedPeriod?.period_name || 'No period selected'}</span>
          </div>
          <div className="self-eval-table-wrap">
            <table className="self-eval-table">
              <thead><tr><th>Name</th><th>Role</th><th>Period</th><th>Overall</th><th>Level</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
                {records.length === 0 && <tr><td colSpan="7">No self evaluations have been saved yet.</td></tr>}
                {records.map((record) => (
                  <tr key={record.id}>
                    <td>{record.full_name}</td>
                    <td>{record.role}</td>
                    <td>{record.evaluation_period}</td>
                    <td>{record.overall_rating || 'Pending'}</td>
                    <td>{record.performance_level || 'Pending'}</td>
                    <td><span className={`self-eval-status ${record.status}`}>{record.status}</span></td>
                    <td><button type="button" className="evaluation-nav-btn secondary" onClick={() => reopen(record.id)}><RotateCcw size={14} /> Reopen</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </section>
    );
  }

  if (showSubmittedPreview) {
    return (
      <section className="admin-box module-wide self-evaluation-page self-eval-modern-page self-eval-submitted-preview page-enter evaluation-form-modal">
        <div className="dipascaf-modal-header self-eval-modern-header">
          <div className="dipascaf-modal-header-text">
            <h2>Self Evaluation Preview</h2>
            <p>This self-evaluation has already been submitted. Only the submitted summary is shown here.</p>
          </div>
          <span className={`self-eval-status ${status}`}>{status}</span>
        </div>

        <SubmittedSelfEvaluationPreview
          answers={answers}
          employee={employee}
          formCode={formCode}
          audienceLabel={audienceLabel}
          categories={selfEvaluationCategories}
          categoryStats={categoryStats}
          computed={computed}
          recordMeta={recordMeta}
          evaluationRole={targetRole}
          definition={templateInfo.definition}
        />
      </section>
    );
  }

  return (
    <section className={`admin-box module-wide self-evaluation-page self-eval-modern-page page-enter evaluation-form-modal ${isPaperSection ? 'self-eval-paper-active' : ''}`}>
      <div className="dipascaf-modal-header self-eval-modern-header">
        <div className="dipascaf-modal-header-text">
          <h2>{isManagedReviewerEdit ? 'Edit Faculty Self Evaluation' : 'Self Evaluation'}</h2>
          <p>
            {isManagedReviewerEdit
              ? `Review and update this submitted faculty self-evaluation. ${managedReviewerRole === 'program_head' ? 'Program Head' : 'Dean'} changes are validated, scope-checked, and recorded in the activity logs.`
              : 'Rate your own performance based on the PMAS criteria. Complete each section, add behavioral evidence when required, then submit your final self-assessment.'}
          </p>
        </div>
        <span className={`self-eval-status ${status}`}>{status}</span>
      </div>

      {!isPaperSection && <div className="evaluation-form-meta self-eval-modern-meta">
        {[
          ['Employee', employee.name],
          ['Position', employee.positionTitle],
          ['Department', employee.department],
          ['Evaluation Form', `${formCode} - ${audienceLabel}`],
          ['Appraisal Period', employee.appraisalPeriod],
        ].map(([label, value]) => (
          <div key={label}><span>{label}</span><strong>{value || 'Not set'}</strong></div>
        ))}
      </div>}

      {(loadError || categoryLoadError) && (
        <div className="notice warning self-eval-load-warning">
          {loadError || categoryLoadError}
        </div>
      )}

      {assignmentMissing && canEdit && (
        <div className="notice warning self-eval-load-warning">
          <div>
            <strong>Self-evaluation assignment not ready.</strong>
            <p>Create the assignment for the current appraisal period before submitting this form.</p>
          </div>
          <button type="button" className="primary-button" onClick={initializeAssignment} disabled={saving}>
            <Plus size={16} /> Create Self-Evaluation Assignment
          </button>
        </div>
      )}

      {successMessage && (
        <div className="self-eval-success-banner">
          <CheckCircle2 size={18} />
          <span>{successMessage}</span>
        </div>
      )}
      {questionnaireUpdated && <div className="notice info self-eval-load-warning"><strong>Questionnaire updated.</strong> Your existing answers were preserved. Review and complete any newly required questions before submitting.</div>}

      <form className={`admin-form evaluation-form self-eval-modern-form ${isPaperSection ? 'self-eval-paper-mode' : ''}`} onSubmit={(event) => event.preventDefault()} autoComplete="off">
        <div className="form-category-header">
          <strong>Sections</strong>
          <small>Move through the PMAS self-evaluation using the same flow as the evaluation questionnaire.</small>
        </div>

        <div className="form-category-nav">
          <label className="form-category-select-label" htmlFor="self-evaluation-section-select">Section</label>
          <select
            id="self-evaluation-section-select"
            className="form-category-select"
            value={activeSectionIndex}
            onChange={(event) => goToSection(Number(event.target.value))}
          >
            {modernSections.map((section, index) => (
              <option key={section.id} value={index}>{section.title}</option>
            ))}
          </select>
        </div>

        <div className="form-category-progress">
          <div className="form-progress-copy">
            <strong>Section {activeSectionIndex + 1} of {modernSections.length}</strong>
            <span>{sectionCompletionPercent}% Completed</span>
          </div>
          <div className="form-progress-track" aria-label={`${sectionCompletionPercent}% completed`}>
            <span style={{ width: `${sectionCompletionPercent}%` }} />
          </div>
        </div>

        <div className="form-scroll-questions">
          <div className={`form-category-panel ${isPaperSection && activeSection?.type !== 'partI' ? 'self-eval-paper self-eval-paper-form self-eval-paper-continuation' : ''}`}>
            {activeSection?.type === 'partI' && (
              <ModernPartISelfEvaluationSection
                answers={answers}
                canEdit={canEdit}
                employee={employee}
                formCode={formCode}
                audienceLabel={audienceLabel}
                title={templateInfo.title}
                template={t}
                showValidation={showValidation}
                validationErrors={validationErrors}
                updateAnswer={updateAnswer}
                updateRow={updateRow}
                addRow={addRow}
                removeRow={removeRow}
              />
            )}
            {activeSection?.type === 'questions' && (
              <>
                {showValidation && Object.keys(validationErrors).some((key) => key.startsWith('dynamic:')) && <div className="notice warning">Complete every required item marked with an asterisk.</div>}
                <DynamicQuestionnaireRenderer
                  definition={{ ...templateInfo.definition, sections: [activeSection] }}
                  answers={{ ...(answers.dynamicResponses || {}), ...(answers.selfRatings || {}) }}
                  onAnswer={updateDynamicResponse}
                  disabled={!canEdit}
                />
              </>
            )}
            {activeSection?.type === 'category' && (
              <ModernCategorySection
                category={activeSection.category}
                answers={answers}
                canEdit={canEdit}
                showValidation={showValidation}
                validationErrors={validationErrors}
                categoryStats={categoryStats}
                setAnswers={setAnswers}
                setValidationErrors={setValidationErrors}
                onDirty={markDraftDirty}
              />
            )}
            {activeSection?.type === 'outputs' && (
              <ModernOutputsSection answers={answers} canEdit={canEdit} showValidation={showValidation} validationErrors={validationErrors} updateRow={updateRow} addRow={addRow} removeRow={removeRow} computed={computed} />
            )}
            {activeSection?.type === 'summary' && (
              <ModernSummarySection answers={answers} canEdit={canEdit} showValidation={showValidation} validationErrors={validationErrors} updateAnswer={updateAnswer} updateRow={updateRow} addRow={addRow} removeRow={removeRow} computed={computed} template={t} />
            )}
            {activeSection?.type === 'confirmation' && (
              <ModernConfirmationSection
                answers={answers}
                appraiseeName={employee.name}
                canEdit={canEdit}
                showValidation={showValidation}
                validationErrors={validationErrors}
                updateAnswer={updateAnswer}
                programHeadReviewedAt={recordMeta?.program_head_reviewed_at || ''}
                approvalRequirements={templateInfo.definition?.approvalRequirements}
              />
            )}
            {activeSection?.type === 'career' && (
              <ModernCareerSection answers={answers} canEdit={canEdit} updateAnswer={updateAnswer} updateCareer={updateCareer} />
            )}
          </div>
        </div>

        <div className="form-submit-row self-eval-modern-actions">
          <div className="form-auto-save-indicator">
            {saving ? (
              <span className="auto-save-saving">Saving...</span>
            ) : submitBlocker ? (
              <span className="auto-save-idle">{submitBlocker} {totalQuestionCount > 0 ? `Answered ${answeredQuestionCount}/${totalQuestionCount} category indicators.` : ''}</span>
            ) : (
              <span className="auto-save-idle">Ready to submit. All required sections and category indicators are complete.</span>
            )}
          </div>
          <div className="form-submit-buttons">
            <button type="button" className="dipascaf-evaluate-btn evaluation-nav-btn secondary" onClick={goPrevious} disabled={activeSectionIndex <= 0}>Previous</button>
            {activeSectionIndex < modernSections.length - 1 ? (
              <button type="button" className="dipascaf-evaluate-btn evaluation-nav-btn" onClick={goNext}>Next</button>
            ) : (
              canEdit && (
                <>
                  {!selfAssignment?.id && (
                    <button type="button" className="dipascaf-evaluate-btn evaluation-nav-btn secondary" onClick={initializeAssignment} disabled={saving}>
                      <Plus size={16} /> Create Assignment
                    </button>
                  )}
                  <button
                    type="button"
                    className="dipascaf-evaluate-btn evaluation-submit-btn"
                    onClick={() => save('submit')}
                    disabled={!canSubmitSelfEvaluation}
                    title={submitBlocker || 'Submit Self-Evaluation'}
                  >
                    <Send size={16} /> {isManagedReviewerEdit ? 'Update Faculty Self-Evaluation' : 'Submit Self-Evaluation'}
                  </button>
                </>
              )
            )}
          </div>
        </div>
      </form>

    </section>
  );
}

function ModernPartISelfEvaluationSection({ answers, canEdit, employee, formCode, audienceLabel, title, template, showValidation, validationErrors, updateAnswer, updateRow, addRow, removeRow }) {
  return (
    <div className="self-eval-paper self-eval-paper-form">
      <OfficialSelfEvaluationHeader formCode={formCode} audienceLabel={audienceLabel} title={title} />

      <div className="self-eval-paper-fields">
        <PaperLine label="Name" value={employee.name || 'Auto-filled employee name'} />
        <PaperLine label="Appraisal Period" value={employee.appraisalPeriod || 'Auto-filled period'} />
        <PaperLine label="Position Title" value={employee.positionTitle || 'Auto-filled position title'} wide />
        <PaperLine label="Department" value={employee.department || 'Auto-filled department'} wide />
      </div>

      <div className="self-eval-section paper-section">
        <h3>Part I - Self-Evaluation</h3>
        <p className="paper-subtitle">(to be accomplished by employee to be appraised)</p>

        <Question title="1" text={template.question1 || 'List down goals you have achieved and other significant accomplishments you have met during the appraisal period.'} />
        {showValidation && validationErrors.achievedGoals && <div className="field-error-label"><AlertCircle size={12} /><span>{validationErrors.achievedGoals}</span></div>}
        <EditableTable columns={['Goals', 'Actual Accomplishment']} rows={answers.achievedGoals} disabled={!canEdit}
          render={(row, index) => (
            <>
              <td><textarea value={row.goals} onChange={(event) => updateRow('achievedGoals', index, 'goals', event.target.value)} disabled={!canEdit || row.approvedGoal} readOnly={!!row.approvedGoal} title={row.approvedGoal ? 'Transferred from the approved Goals Record Sheet' : ''} /></td>
              <td><textarea value={row.accomplishment} onChange={(event) => updateRow('achievedGoals', index, 'accomplishment', event.target.value)} disabled={!canEdit} /></td>
              <RemoveCell disabled={!canEdit || row.approvedGoal || answers.achievedGoals.length === 1} onClick={() => removeRow('achievedGoals', index)} />
            </>
          )}
          onAdd={() => addRow('achievedGoals', { goals: '', accomplishment: '' })}
        />
        <PaperBox label="Other Accomplishments Aside From Goals Achievement" value={answers.otherAccomplishments} onChange={(value) => updateAnswer('otherAccomplishments', value)} disabled={!canEdit} rows={4} />

        <Question title="2" text={template.question2 || 'List also goals that did not meet mutually agreed standards of performance and specify reasons why they were not met.'} />
        <PaperBox value={answers.unmetGoalsReason} onChange={(value) => updateAnswer('unmetGoalsReason', value)} disabled={!canEdit} rows={4} />

        <Question title="3" text={template.question3 || 'What personal strengths do you have that contributed to your performance level during the appraisal period under review? How did they contribute to your performance level?'} />
        <PaperBox value={answers.personalStrengths} onChange={(value) => updateAnswer('personalStrengths', value)} disabled={!canEdit} rows={4} />

        <Question title="4" text={template.question4 || 'How would you evaluate your overall performance considering performance outputs and work behaviors during this period in review?'} />
        {showValidation && validationErrors.overallSelfRating && <div className="field-error-label"><AlertCircle size={12} /><span>{validationErrors.overallSelfRating}</span></div>}
        <div className="self-eval-rating-grid paper-checkbox-grid">
          {ratings.map((item) => (
            <label key={item.value} className="self-eval-radio paper-checkbox">
              <input
                type="radio"
                name="overallSelfRating"
                checked={answers.overallSelfRating === item.value}
                disabled={!canEdit}
                onChange={() => updateAnswer('overallSelfRating', item.value)}
              />
              {item.label}
            </label>
          ))}
        </div>
        {showValidation && validationErrors.ratingBasis && <div className="field-error-label"><AlertCircle size={12} /><span>{validationErrors.ratingBasis}</span></div>}
        <PaperBox label="Please explain your basis for the rating." value={answers.ratingBasis} onChange={(value) => updateAnswer('ratingBasis', value)} disabled={!canEdit} rows={4} />

        <Question title="5" text={template.question5 || 'How can you further contribute your talents, knowledge, and skills to the organization to help improve its overall performance?'} />
        <PaperBox value={answers.furtherContribution} onChange={(value) => updateAnswer('furtherContribution', value)} disabled={!canEdit} rows={4} />
      </div>
    </div>
  );
}

function ModernCategorySection({ category, answers, canEdit, showValidation, validationErrors, categoryStats, setAnswers, setValidationErrors, onDirty }) {
  const stats = categoryStats(category);
  const evidenceKey = `evidence:${category.id}`;
  const evidenceError = showValidation && validationErrors[evidenceKey];
  const evidenceMissingRequired = canEdit && stats.requiresEvidence && !stats.evidence;
  return (
    <>
      <h3>
        {category.title}
        <span className="self-eval-modern-weight">Weight: {category.weight || 0}%</span>
      </h3>
      {category.description && <p className="self-eval-modern-description">{category.description}</p>}
      <div className="form-questions-list">
        {category.questions.map((question, index) => {
          const rating = Number(answers.selfRatings?.[question.id] || 0);
          const errorKey = `rating:${question.id}`;
          const hasError = showValidation && validationErrors[errorKey];
          const isMissingRequired = canEdit && (rating < 1 || rating > 5);
          return (
            <div key={question.id} className={`form-question-row ${hasError ? 'has-validation-error' : ''} ${isMissingRequired ? 'is-missing-required' : ''}`}>
              <div className="form-question-header">
                <span className={`form-question-number ${isMissingRequired ? 'form-question-number-required' : ''}`}>{index + 1}.</span>
                <p className={`form-question-text ${hasError ? 'error-text' : ''}`}>
                  <span>{question.text}</span>
                  {isMissingRequired && <span className="required-mark required-mark-danger">Required</span>}
                </p>
              </div>
              <div className={`rating-btn-group ${hasError ? 'rating-group-error' : ''} ${isMissingRequired ? 'rating-group-required' : ''}`}>
                {[5, 4, 3, 2, 1].map((num) => (
                  <button
                    key={num}
                    type="button"
                    className={`rating-btn ${rating === num ? 'rating-btn-active' : ''} ${!canEdit ? 'rating-btn-disabled' : ''} ${hasError ? 'rating-btn-error' : ''} ${isMissingRequired ? 'rating-btn-required' : ''}`}
                    disabled={!canEdit}
                    title={`${num} - ${ratingLabels[num]}`}
                    onClick={() => {
                      onDirty?.();
                      setAnswers((prev) => ({ ...prev, selfRatings: { ...(prev.selfRatings || {}), [question.id]: num } }));
                      setValidationErrors((prev) => {
                        const next = { ...prev };
                        delete next[errorKey];
                        return next;
                      });
                    }}
                  >
                    <span className="rating-btn-num">{num}</span>
                  </button>
                ))}
              </div>
              {hasError && <div className="field-error-label"><span>{validationErrors[errorKey]}</span></div>}
            </div>
          );
        })}
      </div>
      <div className={`form-category-result ${evidenceError ? 'has-validation-error' : ''} ${evidenceMissingRequired ? 'is-missing-required' : ''}`}>
        <div className="form-category-result-head">
          <div>
            <strong>Category Self-Rating</strong>
            <small>{stats.answered}/{stats.totalQuestions} indicators rated</small>
          </div>
          <span className="form-category-result-pill">{stats.average ? stats.average.toFixed(2) : 'Pending'}</span>
        </div>
        <div className={`form-category-evidence-panel ${evidenceError ? 'has-validation-error' : ''} ${evidenceMissingRequired ? 'requires-evidence' : ''}`}>
          <label>
            <span className="evidence-label-line">
              Behavioral Evidence / Remarks
              {stats.requiresEvidence && <span className={`required-mark ${evidenceMissingRequired ? 'required-mark-danger' : ''}`}>Required</span>}
            </span>
            <textarea
              value={answers.selfEvidence?.[category.id] || ''}
              onChange={(event) => {
                onDirty?.();
                setAnswers((prev) => ({ ...prev, selfEvidence: { ...(prev.selfEvidence || {}), [category.id]: event.target.value } }));
                setValidationErrors((prev) => {
                  const next = { ...prev };
                  delete next[evidenceKey];
                  return next;
                });
              }}
              disabled={!canEdit}
              rows={3}
              placeholder="Describe concrete outputs, behavior, achievements, or performance gaps that support this self-rating."
            />
            {evidenceError && <div className="field-error-label"><span>{validationErrors[evidenceKey]}</span></div>}
          </label>
        </div>
      </div>
    </>
  );
}

function ModernOutputsSection({ answers, canEdit, showValidation, validationErrors, updateRow, addRow, removeRow, computed }) {
  const hasError = showValidation && validationErrors.performanceOutputs;
  const weightComplete = Math.abs(computed.totalWeight - 100) <= 0.001;
  const weightDifference = Math.abs(100 - computed.totalWeight);
  return (
    <>
      <h3>Part II - Performance Outputs Appraisal</h3>
      <p className="self-eval-modern-description">Degree of Achievement of Mutually Agreed Work Goals.</p>
      {hasError && <div className="field-error-label"><AlertCircle size={12} /><span>{validationErrors.performanceOutputs}</span></div>}
      <EditableTable columns={['Goals', 'Weight', 'Actual Accomplishment', 'Standard Met or Rating', 'Weighted Rating']} rows={answers.performanceOutputs} disabled={!canEdit}
        render={(row, index) => {
          const weighted = ((Number(row.weight) || 0) / 100) * ratingValue(row.rating);
          return (
            <>
              <td><textarea value={row.goals} onChange={(e) => updateRow('performanceOutputs', index, 'goals', e.target.value)} disabled={!canEdit || row.approvedGoal} readOnly={!!row.approvedGoal} title={row.approvedGoal ? 'Transferred from the approved Goals Record Sheet' : ''} /></td>
              <td><input type="number" min="0" max="100" value={row.weight} onChange={(e) => updateRow('performanceOutputs', index, 'weight', e.target.value)} disabled={!canEdit || row.approvedGoal} readOnly={!!row.approvedGoal} title={row.approvedGoal ? 'Transferred from the approved Goals Record Sheet' : ''} /></td>
              <td><textarea value={row.accomplishment} onChange={(e) => updateRow('performanceOutputs', index, 'accomplishment', e.target.value)} disabled={!canEdit} /></td>
              <td><select value={row.rating} onChange={(e) => updateRow('performanceOutputs', index, 'rating', e.target.value)} disabled={!canEdit}><option value="">Select</option>{outputRatings.map((r) => <option key={r.code} value={r.code}>{r.label}</option>)}</select></td>
              <td><strong>{weighted ? weighted.toFixed(4) : '0.0000'}</strong></td>
              <RemoveCell disabled={!canEdit || row.approvedGoal || answers.performanceOutputs.length === 1} onClick={() => removeRow('performanceOutputs', index)} />
            </>
          );
        }}
        onAdd={answers.performanceOutputs.some((row) => row.approvedGoal) ? undefined : () => addRow('performanceOutputs', { goals: '', weight: '', accomplishment: '', rating: '' })}
      />
      <div className={`self-eval-weight-total ${weightComplete ? 'is-complete' : 'is-incomplete'}`}>
        <span>
          Total Weight
          {!weightComplete && (
            <small>
              {computed.totalWeight < 100
                ? `${weightDifference.toFixed(2).replace(/\.?0+$/, '')}% remaining`
                : `${weightDifference.toFixed(2).replace(/\.?0+$/, '')}% over the limit`}
            </small>
          )}
        </span>
        <strong>{computed.totalWeight.toFixed(2).replace(/\.?0+$/, '')}% / 100%</strong>
      </div>
      <div className="self-eval-total">Total Weighted Rating for Performance Outputs <strong>{computed.outputs.toFixed(4)}</strong></div>
    </>
  );
}

function ModernSummarySection({ answers, canEdit, showValidation, validationErrors, updateAnswer, updateRow, addRow, removeRow, computed, template }) {
  return (
    <>
      <h3>Part IV - Summary and Overall Rating</h3>
      <p className="self-eval-modern-description">Summarize strengths, areas of improvement, and the computed rating.</p>
      <section className="self-eval-summary-entry">
        <div className="self-eval-summary-prompt">
          <strong>Appraisee&apos;s Strengths</strong>
          <p>{template.strengthsQuestion || "What favorable qualities or attitudes other than those covered by the performance factors does the appraisee have which can help him/her excel in the performance of his/her job?"}</p>
        </div>
        <PaperBox value={answers.appraiseeStrengths} onChange={(value) => updateAnswer('appraiseeStrengths', value)} disabled={!canEdit} rows={4} />
      </section>
      <section className="self-eval-summary-entry">
        <div className="self-eval-summary-prompt">
          <strong>Areas of Improvement</strong>
          <p>{template.improvementInstruction || "List areas in which the appraisee's qualities, attitudes, skills, and performance can be improved in relation to the present position. Itemize action plan to be undertaken in this regard."}</p>
        </div>
        <EditableTable columns={['Areas of Improvement', 'Action Plan', 'Time Frame']} rows={answers.improvementPlans} disabled={!canEdit}
        render={(row, index) => (
          <>
            <td><textarea value={row.area} onChange={(e) => updateRow('improvementPlans', index, 'area', e.target.value)} disabled={!canEdit} /></td>
            <td><textarea value={row.actionPlan} onChange={(e) => updateRow('improvementPlans', index, 'actionPlan', e.target.value)} disabled={!canEdit} /></td>
            <td><input value={row.timeFrame} onChange={(e) => updateRow('improvementPlans', index, 'timeFrame', e.target.value)} disabled={!canEdit} /></td>
            <RemoveCell disabled={!canEdit || answers.improvementPlans.length === 1} onClick={() => removeRow('improvementPlans', index)} />
          </>
        )}
        onAdd={() => addRow('improvementPlans', { area: '', actionPlan: '', timeFrame: '' })}
        />
      </section>
      <div className="form-category-computation-grid self-eval-modern-computation">
        <article><span>Performance Outputs x 0.70</span><strong>{(computed.outputs * 0.7).toFixed(4)}</strong></article>
        <article><span>Performance Factors x 0.30</span><strong>{computed.factors === null ? 'Pending' : (computed.factors * 0.3).toFixed(4)}</strong></article>
        <article><span>Overall Rating</span><strong>{computed.overall === null ? 'Pending' : computed.overall.toFixed(4)}</strong></article>
        <article><span>Level</span><strong>{computed.level || 'Pending'}</strong></article>
      </div>
      <div className="self-eval-table-wrap">
        <table className="self-eval-table paper-rating-guide-table">
          <thead><tr><th colSpan="2">Level of Performance</th></tr></thead>
          <tbody>
            <tr><td>Exceptional</td><td>4.51 to 5.00</td></tr>
            <tr><td>Exceeds Expectations</td><td>3.76 to 4.50</td></tr>
            <tr><td>Meets Expectations</td><td>3.01 to 3.75</td></tr>
            <tr><td>Meets Most Expectations</td><td>2.26 to 3.00</td></tr>
            <tr><td>Does Not Meet Expectations</td><td>2.25 or lower</td></tr>
          </tbody>
        </table>
      </div>
    </>
  );
}

function ModernConfirmationSection({ answers, appraiseeName, canEdit, showValidation, validationErrors, updateAnswer, programHeadReviewedAt, approvalRequirements = {} }) {
  const programHeadName = String(answers.confirmations.appraiser || '').trim();
  const programHeadSignature = answers.confirmations.appraiserSignature || '';
  const programHeadSignatureName = answers.confirmations.appraiserSignatureName || '';
  const programHeadReviewDate = formatReviewDate(programHeadReviewedAt);

  async function handleSignatureUpload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    try {
      const signature = await readSignatureFile(file);
      updateAnswer('confirmations', {
        ...answers.confirmations,
        appraiseeSignature: signature.dataUrl,
        appraiseeSignatureName: signature.name,
      });
      addToast({ type: 'success', text: 'Virtual signature uploaded.' });
    } catch (error) {
      addToast({ type: 'error', text: error.message || 'Unable to upload signature.' });
    }
  }

  function removeSignature() {
    updateAnswer('confirmations', {
      ...answers.confirmations,
      appraiseeSignature: '',
      appraiseeSignatureName: '',
    });
  }

  return (
    <>
      <h3>Comments and Confirmation</h3>
      <PaperBox label="Appraisee's Comments on the Appraisal" value={answers.comments} onChange={(value) => updateAnswer('comments', value)} disabled={!canEdit} rows={4} />
      {showValidation && validationErrors.reviewerComments && <div className="field-error-label"><AlertCircle size={12} /><span>{validationErrors.reviewerComments}</span></div>}
      <p className="self-eval-helper">We have jointly reviewed and discussed this performance appraisal.</p>
      <div className="self-eval-runtime-approval">
        <strong>Approval route</strong>
        <span>{(approvalRequirements.reviewers || ['employee', 'program_head', 'dean']).map((reviewer) => String(reviewer).replaceAll('_', ' ')).join(' → ')}</span>
      </div>
      {showValidation && validationErrors.confirmation && <div className="field-error-label"><AlertCircle size={12} /><span>{validationErrors.confirmation}</span></div>}
      <div className="self-eval-modern-field-grid">
        <div className="self-eval-signature-field">
          <label>Printed Name of Appraisee<input autoComplete="off" value={answers.confirmations.appraisee || appraiseeName || ''} readOnly aria-readonly="true" title="Automatically filled from the appraisee profile" /></label>
          {approvalRequirements.requireEmployeeSignature !== false && <div className={`self-eval-signature-upload ${answers.confirmations.appraiseeSignature ? 'has-signature' : ''} ${showValidation && validationErrors.appraiseeSignature ? 'has-validation-error' : ''}`}>
            <div className="self-eval-signature-preview">
              {answers.confirmations.appraiseeSignature ? (
                <img src={answers.confirmations.appraiseeSignature} alt="Uploaded appraisee signature" />
              ) : (
                <span>No virtual signature uploaded</span>
              )}
            </div>
            <div className="self-eval-signature-actions">
              <label className={`evaluation-nav-btn secondary self-eval-signature-button ${!canEdit ? 'disabled' : ''}`}>
                <Upload size={14} />
                <span>{answers.confirmations.appraiseeSignature ? 'Replace signature' : 'Upload signature'}</span>
                <input type="file" accept="image/*" onChange={handleSignatureUpload} disabled={!canEdit} />
              </label>
              {answers.confirmations.appraiseeSignature && canEdit && (
                <button type="button" className="evaluation-nav-btn secondary self-eval-signature-button" onClick={removeSignature}>
                  <Trash2 size={14} /> Remove
                </button>
              )}
            </div>
            {answers.confirmations.appraiseeSignatureName && <small>{answers.confirmations.appraiseeSignatureName}</small>}
            {showValidation && validationErrors.appraiseeSignature && <div className="field-error-label"><AlertCircle size={12} /><span>{validationErrors.appraiseeSignature}</span></div>}
          </div>}
        </div>
        <label className="self-eval-confirmation-date">Date<input type="date" autoComplete="off" value={answers.confirmations.date || ''} onChange={(e) => updateAnswer('confirmations', { ...answers.confirmations, date: e.target.value })} disabled={!canEdit} /></label>
        {programHeadSignature && (
          <div className="self-eval-signature-field self-eval-reviewer-signature-field">
            <div className="self-eval-reviewer-signature-card">
              <div className="self-eval-reviewer-signature-copy">
                <span>Program Head Signature</span>
                <strong>{programHeadName || 'Program Head'}</strong>
                <div className="self-eval-reviewer-signature-status">
                  <CheckCircle2 size={15} /> {programHeadReviewDate ? 'Reviewed and signed' : 'Signature uploaded'}
                </div>
                {programHeadReviewDate && (
                  <div className="self-eval-reviewer-signature-date">
                    <span>Review date</span>
                    <time dateTime={programHeadReviewedAt}>{programHeadReviewDate}</time>
                  </div>
                )}
                {programHeadSignatureName && <small>{programHeadSignatureName}</small>}
              </div>
              <div className="self-eval-signature-preview">
                <img src={programHeadSignature} alt={`Signature of ${programHeadName || 'Program Head'}`} />
              </div>
            </div>
          </div>
        )}
      </div>
    </>
  );
}

function submittedValue(value, fallback = 'Not provided') {
  const text = String(value ?? '').trim();
  return text === '' ? fallback : text;
}

function SubmittedSelfEvaluationPreview({ answers, employee, formCode, audienceLabel, categories, categoryStats, computed, recordMeta, evaluationRole, definition }) {
  const confirmations = answers.confirmations || {};
  const submittedDate = confirmations.date || '';
  const outputs = (answers.performanceOutputs || []).filter((row) => [row.goals, row.accomplishment, row.weight, row.rating].some((value) => String(value || '').trim() !== ''));
  const improvementPlans = (answers.improvementPlans || []).filter((row) => [row.area, row.actionPlan, row.timeFrame].some((value) => String(value || '').trim() !== ''));
  const reviewerConfirmations = evaluationRole === 'dean' ? [{
    key: 'vpaa',
    label: 'VPAA Confirmation',
    name: confirmations.vpaaReviewer,
    signature: confirmations.vpaaReviewerSignature,
    fileName: confirmations.vpaaReviewerSignatureName,
    reviewedAt: recordMeta?.vpaa_reviewed_at,
    reviewStatus: recordMeta?.vpaa_review_status,
  }] : [
    ...(evaluationRole === 'program_head' ? [] : [{
      key: 'program-head',
      label: 'Program Head Confirmation',
      name: confirmations.appraiser,
      signature: confirmations.appraiserSignature,
      fileName: confirmations.appraiserSignatureName,
      reviewedAt: recordMeta?.program_head_reviewed_at,
      reviewStatus: recordMeta?.program_head_review_status,
    }]),
    {
      key: 'dean',
      label: 'Dean Confirmation',
      name: confirmations.deanReviewer,
      signature: confirmations.deanReviewerSignature,
      fileName: confirmations.deanReviewerSignatureName,
      reviewedAt: recordMeta?.dean_reviewed_at,
      reviewStatus: recordMeta?.dean_review_status,
    },
  ];

  return (
    <div className="self-eval-preview-summary self-eval-paper self-eval-paper-form submitted-self-eval-paper">
      <header className="self-eval-paper-head">
        <strong className="self-eval-form-code">{formCode}</strong>
        <div className="self-eval-school-brand">
          <img src="/assets/images/ndmc-seal.png" alt="" />
          <div>
            <h1>NOTRE DAME OF MIDSAYAP COLLEGE</h1>
            <h2>Performance Appraisal Sheet</h2>
            <p>({audienceLabel})</p>
            <small>Submitted Self-Evaluation</small>
          </div>
        </div>
      </header>

      <div className="self-eval-paper-fields">
        <PaperLine label="Name" value={submittedValue(employee.name, 'Not set')} />
        <PaperLine label="Appraisal Period" value={submittedValue(employee.appraisalPeriod, 'Not set')} />
        <PaperLine label="Position Title" value={submittedValue(employee.positionTitle, 'Not set')} wide />
        <PaperLine label="Department" value={submittedValue(employee.department, 'Not set')} wide />
        <PaperLine label="Submitted Date" value={submittedValue(submittedDate, 'Not recorded')} wide />
      </div>

      <div className="self-eval-preview-score-grid">
        <article><span>Performance Outputs</span><strong>{computed.outputs.toFixed(4)}</strong></article>
        <article><span>Performance Factors</span><strong>{computed.factors === null ? 'Pending' : computed.factors.toFixed(4)}</strong></article>
        <article><span>Overall Rating</span><strong>{computed.overall === null ? 'Pending' : computed.overall.toFixed(4)}</strong></article>
        <article><span>Level</span><strong>{computed.level || 'Pending'}</strong></article>
      </div>

      {(definition?.sections || []).some((section) => section.type === 'questions' && section.visible !== false) && (
        <section className="self-eval-preview-card">
          <h3>Questionnaire Responses</h3>
          <DynamicQuestionnaireRenderer
            definition={{ ...definition, sections: (definition.sections || []).filter((section) => section.type === 'questions') }}
            answers={{ ...(answers.dynamicResponses || {}), ...(answers.selfRatings || {}) }}
            disabled
          />
        </section>
      )}

      <section className="self-eval-preview-card">
        <h3>Self Rating Summary</h3>
        <div className="self-eval-preview-category-list">
          {categories.map((category) => {
            const stats = categoryStats(category);
            return (
              <article key={category.id}>
                <div>
                  <strong>{category.title}</strong>
                  <span>{stats.answered}/{stats.totalQuestions} indicators answered</span>
                </div>
                <b>{stats.average ? stats.average.toFixed(2) : 'Pending'}</b>
                {stats.evidence && <p>{stats.evidence}</p>}
              </article>
            );
          })}
        </div>
      </section>

      <section className="self-eval-preview-card">
        <h3>Performance Outputs</h3>
        {outputs.length === 0 ? (
          <p>No performance outputs were recorded.</p>
        ) : (
          <div className="self-eval-preview-output-list">
            {outputs.map((row, index) => (
              <article key={`${row.goals || 'output'}-${index}`}>
                <strong>{submittedValue(row.goals, `Output ${index + 1}`)}</strong>
                <p>{submittedValue(row.accomplishment, 'No accomplishment details.')}</p>
                <span>Weight: {row.weight || 0}% | Rating: {row.rating || 'Pending'}</span>
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="self-eval-preview-card">
        <h3>Comments and Development Summary</h3>
        <div className="self-eval-preview-text-grid">
          <article><span>Overall Self Rating</span><strong>{submittedValue(answers.overallSelfRating)}</strong></article>
          <article><span>Rating Basis</span><p>{submittedValue(answers.ratingBasis)}</p></article>
          <article><span>Personal Strengths</span><p>{submittedValue(answers.personalStrengths || answers.appraiseeStrengths)}</p></article>
          <article><span>Further Contribution / Goals</span><p>{submittedValue(answers.furtherContribution)}</p></article>
          <article><span>Faculty Comments</span><p>{submittedValue(answers.comments)}</p></article>
        </div>
        {improvementPlans.length > 0 && (
          <div className="self-eval-preview-improvement-list">
            <strong>Areas for Improvement</strong>
            {improvementPlans.map((row, index) => (
              <p key={`${row.area || 'area'}-${index}`}>{submittedValue(row.area)} - {submittedValue(row.actionPlan)}{row.timeFrame ? ` (${row.timeFrame})` : ''}</p>
            ))}
          </div>
        )}
      </section>

      <section className="self-eval-preview-card self-eval-preview-confirmation">
        <h3>Submitted Confirmation</h3>
        <div>
          <article>
            <span>Printed Name of Appraisee</span>
            <strong>{submittedValue(confirmations.appraisee)}</strong>
          </article>
          <article>
            <span>Date</span>
            <strong>{submittedValue(submittedDate, 'Not recorded')}</strong>
          </article>
        </div>
        <div className="self-eval-preview-signature">
          {confirmations.appraiseeSignature ? (
            <img src={confirmations.appraiseeSignature} alt="Submitted appraisee signature" />
          ) : (
            <span>No signature image recorded.</span>
          )}
        </div>
        {confirmations.appraiseeSignatureName && <small>{confirmations.appraiseeSignatureName}</small>}

        <div className="self-eval-preview-reviewer-confirmations">
          {reviewerConfirmations.map((reviewer) => (
            <article className={`self-eval-preview-reviewer-card ${reviewer.signature ? 'is-signed' : 'is-pending'}`} key={reviewer.key}>
              <header>
                <div>
                  <span>{reviewer.label}</span>
                  <strong>{submittedValue(reviewer.name, reviewer.key === 'vpaa' ? 'VPAA' : reviewer.key === 'dean' ? 'Dean' : 'Program Head')}</strong>
                </div>
                <span className="self-eval-preview-review-status">
                  {reviewer.signature ? 'Signed' : 'Not yet signed'}
                </span>
              </header>
              <div className="self-eval-preview-signature reviewer-signature">
                {reviewer.signature ? (
                  <img src={reviewer.signature} alt={`${reviewer.label} signature`} />
                ) : (
                  <span>No reviewer signature recorded.</span>
                )}
              </div>
              <footer>
                <span>Review date: {reviewer.reviewedAt ? formatReviewDate(reviewer.reviewedAt) : 'Not recorded'}</span>
                {reviewer.reviewStatus && <span>Status: {String(reviewer.reviewStatus).replaceAll('_', ' ')}</span>}
                {reviewer.fileName && <small>{reviewer.fileName}</small>}
              </footer>
            </article>
          ))}
        </div>
      </section>
    </div>
  );
}

function ModernCareerSection({ answers, canEdit, updateAnswer, updateCareer }) {
  return (
    <>
      <h3>Part V - Employee Career Development Assessment</h3>
      <PaperBox label="Most Probable Next Job" value={answers.careerDevelopment.nextJob} onChange={(value) => updateAnswer('careerDevelopment', { ...answers.careerDevelopment, nextJob: value })} disabled={!canEdit} rows={2} />
      <div className="self-eval-modern-field-grid">
        <label>Present Career Development Status
          <input
            list="career-development-status-options"
            value={answers.careerDevelopment.status}
            onChange={(e) => updateAnswer('careerDevelopment', { ...answers.careerDevelopment, status: e.target.value })}
            disabled={!canEdit}
            placeholder="Select or type a status"
            autoComplete="off"
          />
          <datalist id="career-development-status-options">
            {careerStatuses.map((item) => <option key={item} value={item} />)}
          </datalist>
        </label>
        <label>
          Development Period
          <input
            list="career-development-period-options"
            value={answers.careerDevelopment.developmentTime}
            onChange={(e) => updateAnswer('careerDevelopment', { ...answers.careerDevelopment, developmentTime: e.target.value })}
            disabled={!canEdit}
            placeholder="Select or type a development period"
            autoComplete="off"
          />
          <datalist id="career-development-period-options">
            {developmentPeriods.map((period) => <option key={period} value={period} />)}
          </datalist>
        </label>
      </div>
      <EditableTable columns={['Assistance Required', 'Potential Difficulties', 'Action Steps', 'Specific Time Table']} rows={answers.careerDevelopment.actionPlans} disabled={!canEdit}
        render={(row, index) => (
          <>
            <td><textarea value={row.assistance} onChange={(e) => updateCareer(index, 'assistance', e.target.value)} disabled={!canEdit} /></td>
            <td><textarea value={row.difficulties} onChange={(e) => updateCareer(index, 'difficulties', e.target.value)} disabled={!canEdit} /></td>
            <td><textarea value={row.actionSteps} onChange={(e) => updateCareer(index, 'actionSteps', e.target.value)} disabled={!canEdit} /></td>
            <td><input value={row.timeTable} onChange={(e) => updateCareer(index, 'timeTable', e.target.value)} disabled={!canEdit} /></td>
            <RemoveCell disabled={!canEdit || answers.careerDevelopment.actionPlans.length === 1} onClick={() => updateAnswer('careerDevelopment', { ...answers.careerDevelopment, actionPlans: answers.careerDevelopment.actionPlans.filter((_, i) => i !== index) })} />
          </>
        )}
        onAdd={() => updateAnswer('careerDevelopment', { ...answers.careerDevelopment, actionPlans: [...answers.careerDevelopment.actionPlans, { assistance: '', difficulties: '', actionSteps: '', timeTable: '' }] })}
      />
    </>
  );
}

function Question({ title, text }) {
  return <div className="self-eval-question"><strong>{title}</strong><p>{text}</p></div>;
}

function AdminSelfEvaluationTablePreview({ title, template, definition, formCode, audienceLabel }) {
  const blankRows = [0, 1, 2, 3, 4];
  const questionDefinition = {
    ...(definition || {}),
    sections: (definition?.sections || []).filter((section) => section.type === 'questions'),
  };
  return (
    <div className="self-eval-paper admin-self-eval-paper-preview self-eval-original-preview">
      <OfficialSelfEvaluationHeader formCode={formCode} audienceLabel={audienceLabel} title={title} />

      <div className="self-eval-paper-fields">
        <PaperLine label="Name" value="Auto-filled employee name" />
        <PaperLine label="Appraisal Period" value="Auto-filled period" />
        <PaperLine label="Position Title" value="Auto-filled position title" wide />
        <PaperLine label="Department" value="Auto-filled department" wide />
      </div>

      <div className="self-eval-section paper-section">
        <h3>Part I - SELF-EVALUATION</h3>
        <p className="paper-subtitle">(to be accomplished by employee to be appraised)</p>
        <Question title="1" text={template.question1 || 'List down goals you have achieved and other significant accomplishments you have met during the appraisal period.'} />
        <PreviewTable columns={['Goals', 'Actual Accomplishment']} rows={blankRows} />
        <PreviewBox label="Other Accomplishments Aside From Goals Achievement" />
        <Question title="2" text={template.question2 || 'List also goals that did not meet mutually agreed standards of performance and specify reasons why they were not met.'} />
        <PreviewBox />
      </div>

      <div className="self-eval-section paper-section self-eval-reference-page-break">
        <Question title="3" text={template.question3 || 'What personal strengths do you have that contributed to your performance level during the appraisal period under review? How did they contribute to your performance level?'} />
        <PreviewBox />
        <Question title="4" text={template.question4 || 'How would you evaluate your overall performance considering performance outputs and work behaviors during this period in review?'} />
        <div className="paper-checkbox-grid self-eval-preview-rating-list">
          {ratings.map((item) => <span key={item.value}><i aria-hidden="true" />{item.label}</span>)}
        </div>
        <PreviewBox label="Please explain your basis for the rating." />
        <Question title="5" text={template.question5 || 'How can you further contribute your talents, knowledge, and skills to the organization to help improve its overall performance?'} />
        <PreviewBox />
        {(questionDefinition.sections || []).length > 0 && (
          <div className="self-eval-preview-supplemental">
            <strong>Additional questionnaire items</strong>
            <DynamicQuestionnaireRenderer definition={questionDefinition} answers={{}} disabled preview />
          </div>
        )}
      </div>

      <div className="self-eval-section paper-section">
        <h3>Part II - PERFORMANCE OUTPUTS APPRAISAL</h3>
        <p className="self-eval-helper">Degree of Achievement of Mutually Agreed Work Goals</p>
        <PreviewTable columns={['Goals', 'Weight', 'Actual Accomplishment', 'Standard Met / Rating', 'Weighted Rating']} rows={blankRows} />
        <div className="self-eval-total">Total Weighted Rating for the Performance Outputs <strong>Auto-computed</strong></div>
      </div>

      <div className="self-eval-section paper-section">
        <h3>Part IV - SUMMARY</h3>
        <Question title="Appraisee's Strengths" text={template.strengthsQuestion} />
        <PreviewBox />
        <Question title="Areas of Improvement" text={template.improvementInstruction} />
        <PreviewTable columns={['Areas of Improvement', 'Action Plan', 'Time Frame']} rows={blankRows} />
      </div>

      <div className="self-eval-section paper-section">
        <h3>Overall Rating</h3>
        <PreviewKeyValueTable rows={[
          ['Score for Performance Outputs x 0.70', 'Auto-computed'],
          ['Score for Performance Factors x 0.30', 'Input / available score'],
          ['Overall Rating', 'Auto-computed'],
          ['Level of Performance', 'Auto-identified'],
        ]} />
      </div>

      <div className="self-eval-section paper-section self-eval-reference-page-break">
        <h3>Level of Performance</h3>
        <PreviewKeyValueTable rows={[
          ['Exceptional', '4.51 to 5.00'],
          ['Exceeds Expectations', '3.76 to 4.50'],
          ['Meets Expectations', '3.01 to 3.75'],
          ['Meets Most Expectations', '2.26 to 3.00'],
          ['Does Not Meet Expectations', '2.25 or lower'],
        ]} />
        <h3>Appraisee's Comments on the Appraisal</h3>
        <PreviewBox />
        <p className="self-eval-helper">We have jointly reviewed and discussed this performance appraisal.</p>
        <div className="self-eval-approval-summary">
          <strong>Required approvals</strong>
          <span>{(definition?.approvalRequirements?.reviewers || ['employee', 'program_head', 'dean']).map((reviewer) => String(reviewer).replaceAll('_', ' ')).join(' → ')}</span>
        </div>
        <PreviewTable columns={['Printed Name and Signature of Appraisee', 'Printed Name and Signature of Appraiser', 'Printed Name and Signature of Reviewer', 'Date']} rows={[0]} />
      </div>

      <div className="self-eval-section paper-section">
        <h3>Part V - EMPLOYEE CAREER DEVELOPMENT ASSESSMENT</h3>
        <PreviewBox label="Most Probable Next Job" />
        <PreviewKeyValueTable rows={[
          ['Present Career Development Status', 'Ready / High potential / Career shift / Present position only'],
        ]} />
        <PreviewTable columns={['Assistance Required', 'Potential Difficulties', 'Action Steps', 'Specific Time Table']} rows={blankRows} />
        <PreviewTable columns={['Signature of Appraiser', 'Signature of Reviewer', 'Date']} rows={[0]} />
      </div>
    </div>
  );
}

function OfficialSelfEvaluationHeader({ formCode, audienceLabel }) {
  return (
    <header className="self-eval-paper-head">
      <strong className="self-eval-form-code">{formCode || 'PMAS FORM 3b'}</strong>
      <div className="self-eval-school-brand">
        <img src="/assets/images/ndmc-seal.png" alt="Notre Dame of Midsayap College seal" />
        <div>
          <h1>NOTRE DAME OF MIDSAYAP COLLEGE</h1>
          <h2>Performance Appraisal Sheet</h2>
          <p>({audienceLabel || 'Faculty'})</p>
        </div>
      </div>
    </header>
  );
}

function PreviewTable({ columns, rows }) {
  return (
    <div className="self-eval-table-wrap">
      <table className="self-eval-table">
        <thead><tr>{columns.map((column) => <th key={column}>{column}</th>)}</tr></thead>
        <tbody>{rows.map((row) => <tr key={row}>{columns.map((column) => <td key={column}>&nbsp;</td>)}</tr>)}</tbody>
      </table>
    </div>
  );
}

function PreviewKeyValueTable({ rows, title = '' }) {
  return (
    <div className="self-eval-table-wrap">
      <table className="self-eval-table paper-computation-table">
        {title && <thead><tr><th colSpan="2">{title}</th></tr></thead>}
        <tbody>{rows.map(([label, value]) => <tr key={label}><th>{label}</th><td>{value}</td></tr>)}</tbody>
      </table>
    </div>
  );
}

function PreviewBox({ label = '' }) {
  return (
    <div className="paper-answer-box preview-answer-box">
      {label && <span>{label}</span>}
      <div aria-hidden="true">&nbsp;</div>
    </div>
  );
}

function PaperLine({ label, value, wide = false }) {
  return (
    <div className={`paper-line-field ${wide ? 'wide' : ''}`}>
      <span>{label}</span>
      <strong>{value || '\u00a0'}</strong>
    </div>
  );
}

function PaperBox({ label = '', value, onChange, disabled, rows = 4 }) {
  return (
    <label className="paper-answer-box">
      {label && <span>{label}</span>}
      <textarea rows={rows} value={value} onChange={(event) => onChange(event.target.value)} disabled={disabled} />
    </label>
  );
}

function Textarea({ label, value, onChange, disabled, required = false, rows = 5 }) {
  return <label>{label}{required ? ' *' : ''}<textarea rows={rows} value={value} onChange={(event) => onChange(event.target.value)} disabled={disabled} /></label>;
}

function EditableTable({ columns, rows, render, onAdd, disabled }) {
  return (
    <div className="self-eval-table-block">
      <div className="self-eval-table-wrap">
        <table className="self-eval-table">
          <thead><tr>{columns.map((column) => <th key={column}>{column}</th>)}<th className="no-print">Action</th></tr></thead>
          <tbody>{rows.map((row, index) => <tr key={index}>{render(row, index)}</tr>)}</tbody>
        </table>
      </div>
      {!disabled && typeof onAdd === 'function' && <button type="button" className="evaluation-nav-btn secondary self-eval-add-row" onClick={onAdd}><Plus size={15} /> Add Row</button>}
    </div>
  );
}

function RemoveCell({ disabled, onClick }) {
  return <td className="no-print self-eval-action-cell"><button type="button" className="self-eval-remove" disabled={disabled} onClick={onClick} aria-label="Remove row" title="Remove row"><span aria-hidden="true"><Trash2 size={18} /></span></button></td>;
}
