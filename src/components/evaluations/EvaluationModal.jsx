import { useEffect, useMemo, useState, useCallback, useRef } from 'react';
import { X, CheckCircle2, AlertTriangle, AlertCircle, ClipboardCheck, Printer, PartyPopper, Sparkles, ArrowRight, Trophy } from 'lucide-react';
import apiFetch from '../../data/api.js';
import { confirmSubmitEvaluation } from '../common/ConfirmationModal.jsx';

const RATING_LABELS = {
  5: 'Exceptional Performance',
  4: 'Very Satisfactory',
  3: 'Satisfactory',
  2: 'Needs Improvement',
  1: 'Poor Performance',
};

const RATING_HELP = {
  5: {
    description: 'Consistently exceeds expectations.',
    example: 'Exemplary work that serves as a model for others. Demonstrates leadership, innovation, and outstanding results in all aspects of the role.',
  },
  4: {
    description: 'Frequently meets and often exceeds expectations.',
    example: 'Consistently delivers quality results. Shows initiative, meets goals, and demonstrates competence beyond basic requirements.',
  },
  3: {
    description: 'Meets expected standards.',
    example: 'Meets job requirements satisfactorily. Completes assigned tasks with acceptable quality. Performs duties as expected.',
  },
  2: {
    description: 'Needs focused improvement.',
    example: 'Areas requiring improvement are evident. May require additional guidance, training, or support to meet role expectations.',
  },
  1: {
    description: 'Significant improvement needed.',
    example: 'Performance falls substantially below expectations. Immediate intervention and a structured improvement plan are recommended.',
  },
};

function getScoreInterpretation(score) {
  if (score >= 4.5) return 'Highly Evident';
  if (score >= 3.5) return 'Evident';
  if (score >= 2.5) return 'Moderately Evident';
  if (score >= 1.5) return 'Slightly Evident';
  return 'Not Evident';
}

function computeCategoryStats(responses, questions) {
  let answered = 0;
  let total = 0;
  questions.forEach((q) => {
    const val = Number(responses[String(q.id)] || 0);
    if (val >= 1 && val <= 5) {
      answered++;
      total += val;
    }
  });
  const average = answered > 0 ? total / answered : 0;
  return { answered, questionCount: questions.length, average, total };
}

function normalizeNumber(value, fallback = 0) {
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

function getSubmittedCategoryResult(category, categoryResults = []) {
  const categorySourceId = normalizeNumber(category.sourceId, NaN);
  const categoryLocalId = normalizeNumber(category.id, NaN);
  const categoryTitle = String(category.title || '').trim().toLowerCase();

  return categoryResults.find((result) => {
    const resultCategoryId = normalizeNumber(result.categoryId ?? result.category_id, NaN);
    const resultTitle = String(result.title || result.category || '').trim().toLowerCase();
    return (
      (Number.isFinite(categorySourceId) && categorySourceId === resultCategoryId) ||
      (Number.isFinite(categoryLocalId) && categoryLocalId === resultCategoryId) ||
      (categoryTitle && resultTitle && categoryTitle === resultTitle)
    );
  });
}

function formatRolePosition(role, position) {
  const cleanRole = String(role || '').trim();
  const cleanPosition = String(position || '').trim();
  if (!cleanRole) return cleanPosition || 'N/A';
  if (!cleanPosition) return cleanRole;
  return cleanRole.toLowerCase() === cleanPosition.toLowerCase()
    ? cleanRole
    : `${cleanRole} ${cleanPosition}`;
}

function escapeReportHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function reportValue(value, fallback = 'N/A') {
  const text = String(value ?? '').trim();
  return escapeReportHtml(text || fallback);
}

function formatReportDate(value) {
  if (!value) return 'N/A';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? reportValue(value)
    : escapeReportHtml(date.toLocaleString([], { dateStyle: 'long', timeStyle: 'short' }));
}

function draftStorageKey(evaluation, formType) {
  if (!evaluation?.id || !formType || formType === 'blocked' || formType === 'none') return '';
  return `pmas-evaluation-draft:${formType}:${evaluation.id}`;
}

function transformFormACategories(apiData) {
  return (apiData.categories || []).map((cat) => ({
    id: `form-a-cat-${cat.id}`,
    sourceId: Number(cat.id),
    title: cat.title,
    description: cat.description || '',
    weight: Number(cat.factor_weight || 0),
    questions: (cat.questions || []).map((q) => ({
      id: `form-a-q-${q.id}`,
      sourceId: Number(q.id),
      text: q.question_text || q.text || '',
    })),
  }));
}

function transformFormBCategories(apiData) {
  const questionsByCategory = new Map();
  (apiData.questions || []).forEach((q) => {
    const key = Number(q.category_id);
    const list = questionsByCategory.get(key) || [];
    list.push({ id: `form-b-q-${q.id}`, sourceId: Number(q.id), text: q.question_text || q.text || '' });
    questionsByCategory.set(key, list);
  });

  return (apiData.categories || []).map((cat) => ({
    id: `form-b-cat-${cat.id}`,
    sourceId: Number(cat.id),
    title: cat.title,
    description: cat.description || '',
    weight: Number(cat.factor_weight || 0),
    questions: questionsByCategory.get(Number(cat.id)) || [],
  }));
}

export default function EvaluationModal({
  evaluation, onClose, onSubmit, readOnly = false, evaluatorRole = '', period = null, assignments = [], onEvaluateNext = null, onProgress = null,
}) {
  const [formData, setFormData] = useState({});
  const [categoryEvidence, setCategoryEvidence] = useState({});
  const [activeCategory, setActiveCategory] = useState(null);
  const [formACategories, setFormACategories] = useState([]);
  const [formBCategories, setFormBCategories] = useState([]);
  const [formALoading, setFormALoading] = useState(false);
  const [formBLoading, setFormBLoading] = useState(false);
  const [formAError, setFormAError] = useState('');
  const [formBError, setFormBError] = useState('');
  const [submitError, setSubmitError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [validationErrors, setValidationErrors] = useState({});
  const [showValidation, setShowValidation] = useState(false);
  const questionRefs = useRef({});
  const pendingMissingScrollRef = useRef(null);
  const pendingFirstQuestionScrollRef = useRef(null);
  const formDataRef = useRef({});
  const categoryEvidenceRef = useRef({});
  const activeCategoryRef = useRef(null);
  const [submittedResults, setSubmittedResults] = useState(null);
  const [showReview, setShowReview] = useState(false);
  const [draftRestoredKey, setDraftRestoredKey] = useState('');
  const [lastSaved, setLastSaved] = useState(null);
  const [autoSaveStatus, setAutoSaveStatus] = useState('');
  const [summaryCopied, setSummaryCopied] = useState(false);
  const formRef = useRef(null); // '', 'saving', 'saved'
  const autoSaveTimerRef = useRef(null);
  const autoSaveDebounceRef = useRef(null);

  // -- Form routing logic ------------------------------------------------
  const evaluateeRole = (evaluation?.role || '').toLowerCase();
  const evaluator = (evaluatorRole || '').toLowerCase();
  const isAdminEvaluator = evaluator === 'admin_hr' || evaluator === 'admin';
  const isDeanOrProgramHead = ['dean', 'program head', 'program_head'].includes(evaluateeRole);
  const isFacultyEvaluatee = evaluateeRole === 'faculty' || evaluateeRole === 'teacher';
  const assignedQuestionnaireType = String(evaluation?.questionnaireType || evaluation?.questionnaire_type || '').toLowerCase();

  const formType = isAdminEvaluator
    ? 'blocked'
    : assignedQuestionnaireType === 'admin'
      ? 'form_a'
      : assignedQuestionnaireType === 'faculty'
        ? 'form_b'
        : isDeanOrProgramHead
          ? 'form_a'
          : isFacultyEvaluatee
            ? 'form_b'
            : 'none';

  const submittedAverage = typeof evaluation?.score === 'number' ? evaluation.score : null;
  const viewOnly = readOnly || evaluation?.status === 'submitted';
  const isSubmittedEvaluation = evaluation?.status === 'submitted';
  const isLockedPeriod = !!(period && !period.is_open);
  const draftKey = draftStorageKey(evaluation, formType);

  useEffect(() => {
    formDataRef.current = formData;
  }, [formData]);

  useEffect(() => {
    categoryEvidenceRef.current = categoryEvidence;
  }, [categoryEvidence]);

  useEffect(() => {
    activeCategoryRef.current = activeCategory;
  }, [activeCategory]);

  // -- Fetch Form A categories from API ----------------------------------
  const fetchFormA = useCallback(async () => {
    if (formType !== 'form_a') return;
    setFormALoading(true);
    setFormAError('');
    try {
      const payload = await apiFetch('/api/form_a_admin.php?action=categories');
      const isOk = payload.ok === true || payload.success === true;
      if (!isOk) {
        throw new Error(payload.message || payload.error || 'Failed to load Form A questionnaire.');
      }
      const categories = transformFormACategories(payload.data || payload);
      setFormACategories(categories);
      if (categories.length > 0) {
        setActiveCategory(categories[0].id);
      }
    } catch (error) {
      setFormAError(error.message);
    } finally {
      setFormALoading(false);
    }
  }, [formType]);

  // -- Fetch Form B categories from API ----------------------------------
  const fetchFormB = useCallback(async () => {
    if (formType !== 'form_b') return;
    setFormBLoading(true);
    setFormBError('');
    try {
      const payload = await apiFetch('/api/form_b_admin.php');
      const isOk = payload.ok === true || payload.success === true;
      if (!isOk) {
        throw new Error(payload.message || payload.error || 'Failed to load Form B questionnaire.');
      }
      const data = payload.data || payload;
      const categories = transformFormBCategories(data);
      setFormBCategories(categories);
      if (categories.length > 0) {
        setActiveCategory(categories[0].id);
      }
    } catch (error) {
      setFormBError(error.message);
    } finally {
      setFormBLoading(false);
    }
  }, [formType]);

  // -- Reset state when evaluation changes --------------------------------
  useEffect(() => {
    setFormData({});
    setCategoryEvidence({});
    setActiveCategory(null);
    setFormACategories([]);
    setFormBCategories([]);
    setFormAError('');
    setFormBError('');
    setSubmitError('');
    setSubmitting(false);
    setSubmittedResults(null);
    setShowReview(false);
    setDraftRestoredKey('');
  }, [evaluation?.id]);

  useEffect(() => {
    fetchFormA();
    fetchFormB();
  }, [fetchFormA, fetchFormB]);

  const categories = formType === 'form_a' ? formACategories : formBCategories;
  const loading = formType === 'form_a' ? formALoading : formBLoading;
  const fetchError = formType === 'form_a' ? formAError : formBError;
  const activeCat = categories.find((c) => c.id === activeCategory);
  const pendingEvaluateesAfterSubmit = useMemo(() => {
    const currentId = Number(evaluation?.id || 0);
    return (Array.isArray(assignments) ? assignments : [])
      .filter((item) => Number(item.id) !== currentId)
      .filter((item) => {
        const status = String(item.status || '').toLowerCase();
        return status !== 'submitted' && status !== 'done';
      })
      .slice(0, 6);
  }, [assignments, evaluation?.id]);
  const submittedCategoryRows = useMemo(() => categories.map((cat) => {
    const submittedCategory = viewOnly && Array.isArray(evaluation?.categoryResults)
      ? getSubmittedCategoryResult(cat, evaluation.categoryResults)
      : null;

    if (submittedCategory) {
      const questionCount = normalizeNumber(submittedCategory.questionCount ?? submittedCategory.question_count, cat.questions.length);
      const totalRating = normalizeNumber(submittedCategory.totalRating ?? submittedCategory.total_rate, 0);
      const average = normalizeNumber(
        submittedCategory.averageRating ?? submittedCategory.average_rating ?? submittedCategory.average,
        questionCount > 0 ? totalRating / questionCount : 0
      );
      const answers = submittedCategory.answers && typeof submittedCategory.answers === 'object'
        ? submittedCategory.answers
        : {};
      const answered = questionCount > 0
        ? Math.max(Object.keys(answers).length, average > 0 ? questionCount : 0)
        : 0;

      return {
        id: cat.id,
        title: submittedCategory.title || cat.title,
        weight: normalizeNumber(submittedCategory.factorWeight ?? submittedCategory.factor_weight, cat.weight),
        answered,
        questionCount,
        average,
        interpretation: average > 0 ? getScoreInterpretation(average) : 'No rating',
      };
    }

    const stats = computeCategoryStats(formData, cat.questions);
    return {
      id: cat.id,
      title: cat.title,
      weight: cat.weight,
      answered: stats.answered,
      questionCount: stats.questionCount,
      average: stats.average,
      interpretation: stats.answered > 0 ? getScoreInterpretation(stats.average) : 'No rating',
    };
  }), [categories, evaluation?.categoryResults, formData, viewOnly]);

  // -- Draft restore / persist --------------------------------------------
  useEffect(() => {
    if (!draftKey || viewOnly || categories.length === 0 || draftRestoredKey === draftKey) return;
    try {
      const rawDraft = window.localStorage.getItem(draftKey);
      if (!rawDraft) {
        setDraftRestoredKey(draftKey);
        return;
      }
      const draft = JSON.parse(rawDraft);
      if (draft && typeof draft === 'object') {
        setFormData(draft.formData && typeof draft.formData === 'object' ? draft.formData : {});
        setCategoryEvidence(
          draft.categoryEvidence && typeof draft.categoryEvidence === 'object'
            ? draft.categoryEvidence
            : draft.questionEvidence && typeof draft.questionEvidence === 'object'
              ? draft.questionEvidence
              : {}
        );
        const restoredCategory = categories.some((cat) => cat.id === draft.activeCategory)
          ? draft.activeCategory
          : categories[0]?.id ?? null;
        setActiveCategory(restoredCategory);
      }
    } catch (_) {
      window.localStorage.removeItem(draftKey);
    } finally {
      setDraftRestoredKey(draftKey);
    }
  }, [categories, draftKey, draftRestoredKey, viewOnly]);

  const saveDraftSnapshot = useCallback((snapshot = {}) => {
    if (submittedResults) return;
    if (!draftKey || viewOnly || categories.length === 0 || draftRestoredKey !== draftKey) return;
    const nextFormData = snapshot.formData ?? formDataRef.current;
    const nextCategoryEvidence = snapshot.categoryEvidence ?? categoryEvidenceRef.current;
    const nextActiveCategory = snapshot.activeCategory ?? activeCategoryRef.current;
    const hasDraftData = Object.keys(nextFormData).length > 0 || Object.keys(nextCategoryEvidence).length > 0;
    try {
      if (!hasDraftData) {
        window.localStorage.removeItem(draftKey);
        return;
      }
      const answeredQuestions = categories.reduce((total, cat) => total + computeCategoryStats(nextFormData, cat.questions).answered, 0);
      const totalQuestions = categories.reduce((total, cat) => total + cat.questions.length, 0);
      window.localStorage.setItem(draftKey, JSON.stringify({
        assignmentId: evaluation?.id,
        formType,
        formData: nextFormData,
        categoryEvidence: nextCategoryEvidence,
        activeCategory: nextActiveCategory,
        answeredQuestions,
        totalQuestions,
        progressPercent: totalQuestions > 0 ? Math.round((answeredQuestions / totalQuestions) * 100) : 0,
        savedAt: new Date().toISOString(),
      }));
      setLastSaved(new Date());
      setAutoSaveStatus('saved');
      if (autoSaveTimerRef.current) clearTimeout(autoSaveTimerRef.current);
      autoSaveTimerRef.current = setTimeout(() => {
        setAutoSaveStatus((prev) => prev === 'saved' ? '' : prev);
        autoSaveTimerRef.current = null;
      }, 2000);
    } catch (_) { /* best-effort */ }
  }, [categories, draftKey, draftRestoredKey, evaluation?.id, formType, submittedResults, viewOnly]);

  // Save draft to localStorage with optional indicator
  const persistDraft = useCallback(() => {
    saveDraftSnapshot();
  }, [saveDraftSnapshot]);

  // Cleanup auto-save timer on unmount
  useEffect(() => {
    return () => {
      if (autoSaveTimerRef.current) clearTimeout(autoSaveTimerRef.current);
      if (autoSaveDebounceRef.current) clearTimeout(autoSaveDebounceRef.current);
    };
  }, []);

  // Periodic auto-save every 30 seconds
  useEffect(() => {
    if (!draftKey || viewOnly || categories.length === 0) return;
    const interval = setInterval(() => {
      setAutoSaveStatus('saving');
      persistDraft();
    }, 30000);
    return () => clearInterval(interval);
  }, [draftKey, viewOnly, categories.length, persistDraft]);

  // Auto-save on beforeunload (tab close)
  useEffect(() => {
    if (!draftKey || viewOnly) return;
    const handleBeforeUnload = () => persistDraft();
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, [draftKey, viewOnly, persistDraft]);

  // Debounced save on state change — saves 1.5s after user stops interacting
  useEffect(() => {
    if (!draftKey || viewOnly || categories.length === 0 || draftRestoredKey !== draftKey) return;
    if (autoSaveDebounceRef.current) clearTimeout(autoSaveDebounceRef.current);
    autoSaveDebounceRef.current = setTimeout(() => {
      setAutoSaveStatus('saving');
      persistDraft();
    }, 1500);
    return () => {
      if (autoSaveDebounceRef.current) clearTimeout(autoSaveDebounceRef.current);
    };
  }, [draftKey, viewOnly, categories.length, draftRestoredKey, formData, categoryEvidence, activeCategory, persistDraft]);

  // -- View-only: restore submitted responses + questionnaireEvidence ------
  useEffect(() => {
    if (!viewOnly || categories.length === 0 || !Array.isArray(evaluation?.categoryResults)) return;
    const nextFormData = {};
    const nextCategoryEvidence = {};

    evaluation.categoryResults.forEach((result) => {
      const category = categories.find((cat) => getSubmittedCategoryResult(cat, [result]));
      if (!category) return;
      const answers = result.answers && typeof result.answers === 'object' ? result.answers : {};

      category.questions.forEach((question) => {
        const answer = answers[String(question.sourceId)] ?? answers[question.sourceId];
        if (answer !== undefined && answer !== null && answer !== '') {
          nextFormData[String(question.id)] = Number(answer);
        }
      });
      nextCategoryEvidence[String(category.id)] = {
        behavioralEvidence: result.behavioralEvidence || '',
      };
    });

    setFormData(nextFormData);
    setCategoryEvidence(nextCategoryEvidence);
    setActiveCategory(categories[0]?.id ?? null);
  }, [categories, evaluation?.categoryResults, viewOnly]);

  // -- Track which categories have validation errors ------------------------
  const missingRequiredByCategory = useMemo(() => {
    return categories.map((cat) => {
      const fields = [];
      cat.questions.forEach((q, index) => {
        const rating = Number(formData[String(q.id)] || 0);
        if (rating < 1 || rating > 5) {
          fields.push({
            key: String(q.id),
            type: 'question',
            label: `Question ${index + 1}`,
            detail: q.text,
          });
        }
      });

      const stats = computeCategoryStats(formData, cat.questions);
      const categoryNote = categoryEvidence[String(cat.id)] || {};
      const behavioralEvidence = (categoryNote.behavioralEvidence || '').trim();
      if (stats.answered === stats.questionCount && stats.questionCount > 0 && (stats.average >= 4.51 || stats.average <= 3.00) && !behavioralEvidence) {
        fields.push({
          key: 'evidence:' + String(cat.id),
          type: 'evidence',
          label: 'Behavioral Evidence',
          detail: 'Required when the completed category rating is 3.00 or below, or 4.51 and above.',
        });
      }

      return {
        id: cat.id,
        title: cat.title,
        weight: cat.weight,
        fields,
      };
    }).filter((cat) => cat.fields.length > 0);
  }, [categories, categoryEvidence, formData]);

  const missingRequiredCount = useMemo(
    () => missingRequiredByCategory.reduce((total, cat) => total + cat.fields.length, 0),
    [missingRequiredByCategory]
  );

  const activeMissingFieldKeys = useMemo(() => {
    const activeMissing = missingRequiredByCategory.find((cat) => cat.id === activeCategory);
    return new Set((activeMissing?.fields || []).map((field) => field.key));
  }, [activeCategory, missingRequiredByCategory]);

  // -- Auto-scroll to first validation error --------------------------------
  useEffect(() => {
    if (showValidation && Object.keys(validationErrors).length > 0) {
      const firstKey = Object.keys(validationErrors)[0];
      scrollAndFocusField(firstKey);
    }
  }, [showValidation, validationErrors]);

  useEffect(() => {
    const scrollKey = pendingMissingScrollRef.current;
    if (!scrollKey) return;
    const activeMissing = missingRequiredByCategory.find((cat) => cat.id === activeCategory);
    if (!activeMissing?.fields.some((field) => field.key === scrollKey)) return;

    scrollAndFocusField(scrollKey);
    pendingMissingScrollRef.current = null;
  }, [activeCategory, missingRequiredByCategory]);

  useEffect(() => {
    const categoryId = pendingFirstQuestionScrollRef.current;
    if (!categoryId || categoryId !== activeCategory) return;
    const category = categories.find((cat) => cat.id === categoryId);
    const firstQuestionKey = category?.questions[0]?.id ? String(category.questions[0].id) : '';
    if (!firstQuestionKey) {
      pendingFirstQuestionScrollRef.current = null;
      return;
    }

    scrollAndFocusField(firstQuestionKey, { block: 'start' });
    pendingFirstQuestionScrollRef.current = null;
  }, [activeCategory, categories]);

  useEffect(() => {
    if (showValidation && missingRequiredCount === 0) {
      setValidationErrors({});
      setShowValidation(false);
    }
  }, [missingRequiredCount, showValidation]);

  // -- Keyboard shortcut: press 1-5 to rate the currently focused question -----
  useEffect(() => {
    const formEl = formRef.current;
    if (!formEl || viewOnly || showReview) return;

    const handleKeyDown = (e) => {
      const num = parseInt(e.key, 10);
      if (isNaN(num) || num < 1 || num > 5) return;

      // Don't interfere with typing in textareas/inputs
      const activeTag = (document.activeElement?.tagName || '').toUpperCase();
      if (activeTag === 'INPUT' || activeTag === 'TEXTAREA' || activeTag === 'SELECT') return;
      if (e.ctrlKey || e.altKey || e.metaKey) return;

      // Find the closest question row from the focused element
      const row = document.activeElement?.closest('.form-question-row');
      if (!row) return;

      const qId = row.getAttribute('data-question-id');
      if (!qId) return;

      e.preventDefault();

      // Set the rating
      const nextFormData = { ...formDataRef.current, [qId]: num };
      formDataRef.current = nextFormData;
      setFormData(nextFormData);
      saveDraftSnapshot({ formData: nextFormData });

      // Clear validation for this field if present
      if (showValidation && validationErrors[qId]) {
        setValidationErrors((prev) => {
          const next = { ...prev };
          delete next[qId];
          return next;
        });
      }
    };

    formEl.addEventListener('keydown', handleKeyDown);
    return () => formEl.removeEventListener('keydown', handleKeyDown);
  }, [saveDraftSnapshot, viewOnly, showReview, showValidation, validationErrors]);

  // -- Compute category-level results from per-question ratings ------------
  const overallStats = useMemo(() => {
    if (categories.length === 0) return { finalScore: 0, interpretation: 'No data', weightedCategories: [] };
    const weightedCategories = categories.map((cat) => {
      const stats = computeCategoryStats(formData, cat.questions);
      return {
        ...cat,
        stats,
        weightedScore: (stats.average * cat.weight) / 100,
      };
    });
    const finalScore = weightedCategories.reduce((sum, c) => sum + c.weightedScore, 0);
    return {
      finalScore: Math.round(finalScore * 10000) / 10000,
      interpretation: getScoreInterpretation(finalScore),
      weightedCategories,
    };
  }, [categories, formData]);

  // -- Validate all ratings and required evidence (returns per-field errors) --
  function validateAll() {
    if (formType === 'none' || formType === 'blocked') return { _banner: 'Cannot submit this form type.' };

    const errors = {};

    for (const cat of categories) {
      for (const q of cat.questions) {
        const rating = Number(formData[String(q.id)] || 0);
        if (rating < 1 || rating > 5) {
          errors[String(q.id)] = 'This question is required.';
        }
      }

      const stats = computeCategoryStats(formData, cat.questions);
      const categoryNote = categoryEvidence[String(cat.id)] || {};
      const behavioralEvidence = (categoryNote.behavioralEvidence || '').trim();
      if (stats.answered === stats.questionCount && stats.questionCount > 0 && (stats.average >= 4.51 || stats.average <= 3.00) && !behavioralEvidence) {
      errors['evidence:' + String(cat.id)] = 'Behavioral Evidence is required before proceeding to the next category.';
      }
    }

    return Object.keys(errors).length > 0 ? errors : null;
  }

  // -- Validate only the current category -----------------------------------
  function validateCurrentCategory() {
    if (!activeCat || formType === 'none' || formType === 'blocked') return null;
    const errors = {};

    for (const q of activeCat.questions) {
      const rating = Number(formData[String(q.id)] || 0);
      if (rating < 1 || rating > 5) {
        errors[String(q.id)] = 'This question is required.';
      }
    }

    const stats = computeCategoryStats(formData, activeCat.questions);
    const categoryNote = categoryEvidence[String(activeCat.id)] || {};
    const behavioralEvidence = (categoryNote.behavioralEvidence || '').trim();
    if (stats.answered === stats.questionCount && stats.questionCount > 0 && (stats.average >= 4.51 || stats.average <= 3.00) && !behavioralEvidence) {
      errors['evidence:' + String(activeCat.id)] = 'Behavioral Evidence is required before proceeding to the next category.';
    }

    return Object.keys(errors).length > 0 ? errors : null;
  }

  // -- Build payload for API submission -----------------------------------
  function buildFormAPayload(categories, formData, categoryEvidence) {
    const result = {};
    categories.forEach((cat) => {
      const answers = {};
      cat.questions.forEach((q) => {
        const val = formData[String(q.id)];
        if (val && Number(val) >= 1 && Number(val) <= 5) {
          answers[String(q.sourceId)] = Number(val);
        }
      });
      const categoryNote = categoryEvidence[String(cat.id)] || {};
      result[String(cat.sourceId)] = {
        answers,
        behavioral_evidence: String(categoryNote.behavioralEvidence || '').trim(),
      };
    });
    return result;
  }

  function buildFormBPayload(categories, formData, categoryEvidence) {
    return {
      categories: categories.map((cat) => {
        const answers = {};
        cat.questions.forEach((q) => {
          const val = formData[String(q.id)];
          if (val && Number(val) >= 1 && Number(val) <= 5) {
            answers[String(q.sourceId)] = Number(val);
          }
        });
        const categoryNote = categoryEvidence[String(cat.id)] || {};
        return {
          category_id: cat.sourceId,
          answers,
          behavioral_evidence: String(categoryNote.behavioralEvidence || '').trim(),
          reason_for_rating: String(categoryNote.reasonForRating || '').trim(),
        };
      }),
    };
  }

  // -- Submit evaluation ---------------------------------------------------
  async function handleSubmit(event) {
    event.preventDefault();
    if (viewOnly || submitting) return;

    const errors = validateAll();
    if (errors) {
      goToMissingField();
      return;
    }

    const confirmed = await confirmSubmitEvaluation();
    if (!confirmed) return;

    setSubmitting(true);
    setSubmitError('');
    setValidationErrors({});
    setShowValidation(false);

    try {
      const evaluationPeriod = evaluation.periodName || evaluation.period || new Date().toISOString().slice(0, 7);
      const endpoint = formType === 'form_a' ? '/api/form_a_admin.php' : '/api/form_b_admin.php';
      const payload = {
        action: 'submit',
        assignment_id: evaluation.id,          ...(formType === 'form_a'
          ? {
              form_a_payload: buildFormAPayload(categories, formData, categoryEvidence),
              evaluation_period: evaluationPeriod,
            }
          : {
              form_b_payload: buildFormBPayload(categories, formData, categoryEvidence),
              evaluation_period: evaluationPeriod,
            }),
        final_score: overallStats.finalScore,
        weighted_categories: overallStats.weightedCategories,
        interpretation: overallStats.interpretation,
      };

      const result = await apiFetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      const isOk = result.ok === true || result.success === true;
      if (!isOk) {
        throw new Error(result.message || result.error || 'Submission failed.');
      }

      if (draftKey) {
        window.localStorage.removeItem(draftKey);
      }

      setSubmittedResults(overallStats);
      onSubmit(evaluation.id, overallStats.finalScore, { keepOpen: true });
    } catch (error) {
      setSubmitError(error.message);
    } finally {
      setSubmitting(false);
    }
  }

  // -- Rating change handler -----------------------------------------------
  function handleRatingChange(questionId) {
    return (value) => {
      const nextFormData = { ...formDataRef.current, [String(questionId)]: value };
      formDataRef.current = nextFormData;
      setFormData(nextFormData);
      saveDraftSnapshot({ formData: nextFormData });
    };
  }

  function handleCategoryEvidenceChange(categoryId, field) {
    return (value) => {
      const current = categoryEvidenceRef.current;
      const nextCategoryEvidence = {
        ...current,
        [String(categoryId)]: {
          ...(current[String(categoryId)] || {}),
          [field]: value,
        },
      };
      categoryEvidenceRef.current = nextCategoryEvidence;
      setCategoryEvidence(nextCategoryEvidence);
      saveDraftSnapshot({ categoryEvidence: nextCategoryEvidence });
    };
  }

  function scrollAndFocusField(fieldKey, options = {}) {
    const { block = 'center' } = options;
    window.setTimeout(() => {
      const el = questionRefs.current[fieldKey];
      if (!el) return;
      el.scrollIntoView({ behavior: 'smooth', block });
      const focusTarget = el.querySelector('button:not([disabled]), textarea:not([readonly]), input:not([disabled]), select:not([disabled])');
      if (focusTarget && typeof focusTarget.focus === 'function') {
        focusTarget.focus({ preventScroll: true });
      } else if (typeof el.focus === 'function') {
        el.focus({ preventScroll: true });
      }
    }, 80);
  }

  function goToCategory(categoryId, options = {}) {
    const { scrollToFirstQuestion = true, clearValidation = false } = options;
    if (!categoryId) return;
    if (scrollToFirstQuestion) {
      pendingFirstQuestionScrollRef.current = categoryId;
    }
    activeCategoryRef.current = categoryId;
    setActiveCategory(categoryId);
    saveDraftSnapshot({ activeCategory: categoryId });
    if (clearValidation && showValidation) {
      setShowValidation(false);
    }
  }

  function closeWithDraftSave() {
    saveDraftSnapshot();
    onClose();
  }

  function goToCurrentCategoryMissingField(errors) {
    const firstKey = Object.keys(errors || {})[0];
    if (!firstKey) return;
    setValidationErrors((prev) => ({ ...prev, ...errors }));
    setShowValidation(true);
    setShowReview(false);
    pendingMissingScrollRef.current = firstKey;
    scrollAndFocusField(firstKey);
  }

  function goToMissingField(targetField = null) {
    const targetCategory = targetField
      ? missingRequiredByCategory.find((cat) => cat.fields.some((field) => field.key === targetField.key))
      : missingRequiredByCategory[0];
    const target = targetField || targetCategory?.fields[0];
    if (!targetCategory || !target) return;

    const errors = validateAll() || {};
    setValidationErrors(errors);
    setShowValidation(true);
    setShowReview(false);
    pendingMissingScrollRef.current = target.key;
    setActiveCategory(targetCategory.id);
  }

  function renderQuestion(q, index, isViewOnly) {
    const qId = String(q.id);
    const ratingVal = Number(formData[qId] || 0);
    const hasError = !isViewOnly && showValidation && activeMissingFieldKeys.has(qId) && Boolean(validationErrors[qId]);
    const errorMsg = validationErrors[qId] || 'This question is required.';

    return (
      <div key={qId} className={'form-question-row' + (hasError ? ' has-validation-error' : '')} ref={setQuestionRef(qId)} data-question-id={qId}>
        <div className="form-question-header">
          <span className="form-question-number">{index + 1}.</span>
          <p className={'form-question-text' + (hasError ? ' error-text' : '')}>
            {hasError && <AlertCircle className="field-warning-dot" size={14} aria-hidden="true" />}
            <span>{q.text}</span>
          </p>
        </div>

        <div className={'rating-btn-group' + (hasError ? ' rating-group-error' : '')}>
          {[5, 4, 3, 2, 1].map((num) => {
            return (
              <button
                key={num}
                type="button"
                className={'rating-btn ' + (ratingVal === num ? 'rating-btn-active' : '') + (isViewOnly ? ' rating-btn-disabled' : '') + (hasError ? ' rating-btn-error' : '')}
                onClick={() => {
                  if (!isViewOnly) {
                    handleRatingChange(qId)(num);
                    // Clear validation for this field when user provides a rating
                    if (showValidation && validationErrors[qId]) {
                      setValidationErrors((prev) => {
                        const next = { ...prev };
                        delete next[qId];
                        return next;
                      });
                    }
                  }
                }}
                disabled={isViewOnly}
                data-rating={num}
                title={num + ' - ' + RATING_LABELS[num]}
              >
                <span className="rating-btn-num">{num}</span>
              </button>
            );
          })}
        </div>
        {hasError && (
          <div className="field-error-label">
            <AlertCircle size={12} />
            <span>{errorMsg}</span>
          </div>
        )}
      </div>
    );
  }

  function renderSharedRatingGuide() {
    if (viewOnly || submittedResults) return null;

    return (
      <section className="form-rating-guide-card" aria-label="Rating guide">
        <div className="form-rating-guide-head">
          <strong>Rating Guide</strong>
          <span>Use these meanings when selecting 5, 4, 3, 2, or 1.</span>
        </div>
        <div className="form-rating-guide-list">
          {[5, 4, 3, 2, 1].map((num) => (
            <article key={num} className="form-rating-guide-item">
              <strong>{num}</strong>
              <span>{RATING_LABELS[num]}</span>
              <small>{RATING_HELP[num].description}</small>
            </article>
          ))}
        </div>
      </section>
    );
  }

  function renderCategoryResult(cat, isViewOnly) {
    const stats = computeCategoryStats(formData, cat.questions);
    const weightedScore = (stats.average * cat.weight) / 100;
    const evidence = categoryEvidence[String(cat.id)] || {};
    const evidenceText = (evidence.behavioralEvidence || '').trim();
    const allAnswered = stats.questionCount > 0 && stats.answered === stats.questionCount;
    const requiresBehavioralEvidence = allAnswered && (stats.average >= 4.51 || stats.average <= 3.00);
    const needsRequiredEvidence = requiresBehavioralEvidence && !evidenceText;
    const evidenceErrorKey = 'evidence:' + String(cat.id);
    const hasEvidenceError = !isViewOnly && showValidation && activeMissingFieldKeys.has(evidenceErrorKey) && Boolean(validationErrors[evidenceErrorKey]);
    const evidenceErrorMsg = validationErrors[evidenceErrorKey] || 'Behavioral Evidence is required before proceeding to the next category.';

    return (
      <div className={'form-category-result' + (hasEvidenceError ? ' has-validation-error' : '')} ref={hasEvidenceError ? setQuestionRef(evidenceErrorKey) : null}>
        <div className="form-category-result-head">
          <div>
            <strong>Automatic Category Computation</strong>
            <small>{stats.answered}/{stats.questionCount} questions rated. Values update automatically.</small>
          </div>
          {stats.average > 0 && <span className="form-category-result-pill">{getScoreInterpretation(stats.average)}</span>}
        </div>

        <div className="form-category-formula" aria-live="polite">
          <div><span>Average Rating</span><output>{stats.average > 0 ? stats.average.toFixed(2) : '—'}</output></div>
          <b aria-hidden="true">×</b>
          <div><span>Factor Weight</span><output>{cat.weight}%</output></div>
          <b aria-hidden="true">=</b>
          <div className="formula-result"><span>Weighted Score</span><output>{allAnswered ? weightedScore.toFixed(2) : '—'}</output></div>
        </div>

        <div className={'form-category-evidence-panel' + (hasEvidenceError ? ' has-validation-error' : '') + (needsRequiredEvidence && showValidation ? ' requires-evidence' : '')}>
          <label>
            <span className="evidence-label-line">
              {hasEvidenceError && <AlertCircle className="field-warning-dot" size={14} aria-hidden="true" />}
              Behavioral Evidence {requiresBehavioralEvidence && (
                <span className="required-mark">
                  <AlertTriangle size={13} aria-hidden="true" />
                  Required
                </span>
              )}
            </span>
            <textarea
              value={evidence.behavioralEvidence || ''}
              onChange={(e) => {
                if (!isViewOnly) {
                  handleCategoryEvidenceChange(cat.id, 'behavioralEvidence')(e.target.value);
                  // Clear evidence validation error when user starts typing
                  if (showValidation && validationErrors[evidenceErrorKey]) {
                    setValidationErrors((prev) => {
                      const next = { ...prev };
                      delete next[evidenceErrorKey];
                      return next;
                    });
                  }
                }
              }}
              placeholder="Describe the specific observed behavior, output, achievement, or performance gap supporting this category rating. Include concrete examples and specific outcomes."
              rows={3}
              readOnly={isViewOnly}
              className={hasEvidenceError ? 'textarea-error' : ''}
            />
            {!isViewOnly && showValidation && needsRequiredEvidence && !hasEvidenceError && (
              <div className="evidence-required-warning">
                <AlertTriangle size={13} />
                <span>Behavioral Evidence is required before proceeding to the next category.</span>
              </div>
            )}
            {hasEvidenceError && (
              <div className="field-error-label">
                <AlertCircle size={12} />
                <span>{evidenceErrorMsg}</span>
              </div>
            )}
          </label>

        </div>
      </div>
    );
  }

  function renderReviewSummary() {
    const { weightedCategories } = overallStats;
    const errors = validateAll();
    const hasErrors = errors !== null;
    const completedCategories = weightedCategories.filter((cat) => cat.stats.questionCount > 0 && cat.stats.answered === cat.stats.questionCount).length;
    const reviewCompletionPercent = totalQuestions > 0 ? Math.round((answeredQuestions / totalQuestions) * 100) : 0;

    return (
      <div className="review-summary-wrap">
        <div className="review-summary-header">
          <CheckCircle2 size={22} />
          <div>
            <strong>Review Summary</strong>
            <small>Review category completion and required evidence before submitting. Category scores appear after the evaluation is done.</small>
          </div>
        </div>

        <div className="review-summary-metrics" aria-label="Review progress">
          <article className="review-summary-metric primary">
            <span>Completion</span>
            <strong>{reviewCompletionPercent}%</strong>
            <div className="review-summary-progress" aria-hidden="true">
              <span style={{ width: `${reviewCompletionPercent}%` }} />
            </div>
          </article>
          <article className="review-summary-metric">
            <span>Questions Rated</span>
            <strong>{answeredQuestions}/{totalQuestions}</strong>
          </article>
          <article className="review-summary-metric">
            <span>Categories Ready</span>
            <strong>{completedCategories}/{weightedCategories.length}</strong>
          </article>
          <article className={'review-summary-metric ' + (hasErrors ? 'warning' : 'success')}>
            <span>Status</span>
            <strong>{hasErrors ? 'Needs Fix' : 'Ready'}</strong>
          </article>
        </div>

        {hasErrors && (
          <div className="review-summary-errors">
            <AlertTriangle size={18} />
              <div className="review-summary-errors-content">
              <strong>{missingRequiredCount} field{missingRequiredCount > 1 ? 's' : ''} need{missingRequiredCount === 1 ? 's' : ''} attention</strong>
              <small>Please fix all missing fields before submitting. Click a category below to jump to it.</small>
            </div>
          </div>
        )}

        <div className="review-summary-categories">
          {weightedCategories.map((cat) => {
            const catMissing = [];
            for (const q of cat.questions) {
              const rating = Number(formData[String(q.id)] || 0);
              if (rating < 1 || rating > 5) catMissing.push(q);
            }
            const evidence = categoryEvidence[String(cat.id)] || {};
            const allAnswered = cat.stats.questionCount > 0 && cat.stats.answered === cat.stats.questionCount;
            const needsEvidence = allAnswered && (cat.stats.average >= 4.51 || cat.stats.average <= 3.00);
            const evidenceMissing = needsEvidence && !(evidence.behavioralEvidence || '').trim();
            const hasCatErrors = hasErrors && (catMissing.length > 0 || evidenceMissing);
            const evidenceStatusClass = evidenceMissing ? 'missing' : 'provided';

            return (
              <div key={cat.id} className={'review-category-card' + (hasCatErrors ? ' has-errors' : '')}>
                <div className="review-category-head">
                  <div className="review-category-title">
                    <span className="review-category-label">Category</span>
                    <span className="review-category-name">{cat.title}</span>
                  </div>
                  <span className="review-category-weight">Weight: {cat.weight}%</span>
                </div>
                <div className="review-category-stats" aria-label={`${cat.title} review details`}>
                  <div className="review-stat">
                    <span>Questions Rated</span>
                    <strong>{cat.stats.answered}/{cat.stats.questionCount}</strong>
                  </div>
                  <div className="review-stat">
                    <span>Evidence Status</span>
                    <strong>
                      <span className={`review-evidence-badge ${evidenceStatusClass}`}>
                        {evidenceMissing ? 'Missing' : 'Provided'}
                      </span>
                    </strong>
                  </div>
                  <div className="review-stat">
                    <span>Weight Percentage</span>
                    <strong>{cat.weight}%</strong>
                  </div>
                </div>
                <div className="review-category-progress" aria-hidden="true">
                  <span style={{ width: `${cat.stats.questionCount > 0 ? Math.round((cat.stats.answered / cat.stats.questionCount) * 100) : 0}%` }} />
                </div>
                {hasCatErrors && (
                  <div className="review-category-errors">
                    <AlertCircle size={14} />
                    <span>
                      {catMissing.length > 0 && `${catMissing.length} question${catMissing.length > 1 ? 's' : ''} missing`}
                      {catMissing.length > 0 && evidenceMissing && ' • '}
                      {evidenceMissing && 'Behavioral Evidence required'}
                    </span>
                    <button type="button" className="review-jump-btn" onClick={() => { goToCategory(cat.id); setShowReview(false); }}>
                      Jump to category
                    </button>
                  </div>
                )}
              </div>
            );
          })}
        </div>

        <div className="review-summary-footer">
          <div className="review-final-score">
            <div className="review-final-score-left">
              <strong>Evaluation ready for submission</strong>
              <span className="review-interpretation-label">
                {hasErrors ? 'Complete highlighted items first.' : 'All required questions and evidence are complete.'}
              </span>
            </div>
            <div className="review-final-score-right">
              <strong>{overallStats.finalScore.toFixed(2)}</strong>
              <span>/ 5.00</span>
            </div>
          </div>
          <div className="review-actions">
            <button
              type="button"
              className="dipascaf-evaluate-btn evaluation-nav-btn secondary review-back-btn"
              onClick={goBackFromReview}
            >
              Back to Edit
            </button>
            <button
              type="submit"
              className="dipascaf-evaluate-btn evaluation-submit-btn"
              disabled={submitting}
            >
              {submitting ? 'Submitting...' : hasErrors ? 'Fix Errors & Submit' : 'Submit Evaluation'}
            </button>
          </div>
        </div>
      </div>
    );
  }

  function renderResults() {
    if (!submittedResults) return null;
    const { finalScore, interpretation } = submittedResults;
    const hasPendingEvaluatees = pendingEvaluateesAfterSubmit.length > 0;
    const completedName = evaluation.fullName || evaluation.evaluateeName || 'this evaluation';
    const confettiPieces = Array.from({ length: hasPendingEvaluatees ? 36 : 52 }, (_, index) => index);

    return (
      <div className={`evaluation-submit-success ${hasPendingEvaluatees ? 'has-next' : 'all-done'}`}>
        <div className="evaluation-confetti" aria-hidden="true">
          {confettiPieces.map((piece) => (
            <span key={piece} style={{ '--piece': piece }} />
          ))}
        </div>

        <div className="evaluation-success-hero">
          <div className="evaluation-success-icon">
            {hasPendingEvaluatees ? <PartyPopper size={28} /> : <Trophy size={30} />}
          </div>
          <div>
            <span>{hasPendingEvaluatees ? 'Evaluation submitted' : 'Congratulations'}</span>
            <h3>{hasPendingEvaluatees ? 'Success notification sent.' : 'All required evaluations are complete.'}</h3>
            <p>
              {hasPendingEvaluatees
                ? `${completedName} has been recorded successfully. Choose the next pending evaluatee to continue.`
                : 'Your evaluation process is fully done. Thank you for completing every required evaluation.'}
            </p>
          </div>
          <div className="evaluation-success-score">
            <strong>{finalScore.toFixed(2)}</strong>
            <span>{interpretation}</span>
          </div>
        </div>

        {hasPendingEvaluatees ? (
          <div className="evaluation-next-step">
            <div className="evaluation-next-head">
              <div>
                <Sparkles size={18} />
                <strong>Who would you like to evaluate next?</strong>
              </div>
              <span>{pendingEvaluateesAfterSubmit.length} available</span>
            </div>
            <div className="evaluation-next-grid">
              {pendingEvaluateesAfterSubmit.map((item) => {
                const name = item.fullName || item.evaluateeName || 'Assigned Employee';
                const initial = name.charAt(0).toUpperCase();
                const roleLabel = item.section === 'self'
                  ? 'Self Evaluation'
                  : formatRolePosition(item.role || item.evaluateeRole, item.position || item.evaluateePosition);
                return (
                  <button
                    key={item.id}
                    type="button"
                    className="evaluation-next-card"
                    onClick={() => onEvaluateNext && onEvaluateNext(item)}
                  >
                    <span className="evaluation-next-avatar">{initial}</span>
                    <span className="evaluation-next-copy">
                      <strong>{name}</strong>
                      <small>{roleLabel}</small>
                    </span>
                    <ArrowRight size={16} />
                  </button>
                );
              })}
            </div>
          </div>
        ) : (
          <div className="evaluation-complete-panel">
            <CheckCircle2 size={20} />
            <div>
              <strong>Evaluation process fully done</strong>
              <span>You may close this window or review submitted records from your dashboard.</span>
            </div>
          </div>
        )}

        <div className="evaluation-success-actions">
          <button type="button" className="dipascaf-evaluate-btn evaluation-nav-btn secondary" onClick={closeWithDraftSave}>
            {hasPendingEvaluatees ? 'Back to Dashboard' : 'Close'}
          </button>
        </div>
      </div>
    );
  }

  const activeCategoryIndex = Math.max(0, categories.findIndex((cat) => cat.id === activeCategory));
  const activeCategoryNumber = categories.length > 0 ? activeCategoryIndex + 1 : 0;
  const answeredQuestions = categories.reduce((total, cat) => total + computeCategoryStats(formData, cat.questions).answered, 0);
  const totalQuestions = categories.reduce((total, cat) => total + cat.questions.length, 0);
  const completionPercent = totalQuestions > 0 ? Math.round((answeredQuestions / totalQuestions) * 100) : 0;

  useEffect(() => {
    if (!onProgress || !evaluation?.id || viewOnly || submittedResults) return;
    onProgress(evaluation.id, {
      answeredQuestions,
      totalQuestions,
      progressPercent: completionPercent,
      status: completionPercent > 0 ? 'in_progress' : 'pending',
    });
  }, [answeredQuestions, completionPercent, evaluation?.id, onProgress, submittedResults, totalQuestions, viewOnly]);

  // -- Guard: no evaluation -----------------------------------------------
  if (!evaluation) return null;

  // -- Guard: locked period pending evaluations -----------------------------
  if (isLockedPeriod && !isSubmittedEvaluation) {
    const targetName = evaluation.fullName || evaluation.evaluateeName || 'this faculty member';
    return (
      <div className="modal-backdrop" role="presentation">
        <section className="evaluation-form-modal" role="dialog" aria-modal="true" aria-labelledby="evaluation-locked-title">
          <button type="button" className="modal-close modal-icon-close" onClick={closeWithDraftSave} aria-label="Close evaluation form"><X size={18} /></button>
          <div className="dipascaf-empty">
            <h2 id="evaluation-locked-title">Evaluation Period Locked</h2>
            <p>You are not allowed to evaluate {targetName} because the evaluation period is locked.</p>
            <p>Approach the Admin to unlock the evaluation period before answering or submitting this evaluation.</p>
            <p>{period.period_name} {period.date_end ? ' - Due date: ' + period.date_end : ''}</p>
          </div>
        </section>
      </div>
    );
  }

  // -- Blocked: Admin users cannot evaluate --------------------------------
  if (formType === 'blocked') {
    return (
      <div className="dipascaf-modal">
        <div className="dipascaf-modal-panel">
          <div className="dipascaf-modal-header">
            <div className="dipascaf-modal-header-text">
              <h2>Evaluation Not Available</h2>
            </div>
            <button type="button" className="dipascaf-modal-close modal-icon-close" onClick={closeWithDraftSave} aria-label="Close modal"><X size={18} /></button>
          </div>
          <div className="notice error" style={{ padding: '1rem', background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: '0.5rem', color: '#991b1b', fontWeight: 500 }}>
            Admin users are not allowed to submit evaluations.
          </div>
        </div>
      </div>
    );
  }

  // -- No form type --------------------------------------------------------
  if (formType === 'none') {
    return (
      <div className="dipascaf-modal">
        <div className="dipascaf-modal-panel">
          <div className="dipascaf-modal-header">
            <div className="dipascaf-modal-header-text">
              <h2>No Questionnaire Available</h2>
            </div>
            <button type="button" className="dipascaf-modal-close modal-icon-close" onClick={closeWithDraftSave} aria-label="Close modal"><X size={18} /></button>
          </div>
          <div className="notice info">No questionnaire form is assigned for this role.</div>
        </div>
      </div>
    );
  }

  const formTitle = viewOnly
    ? 'Evaluation Details'
    : formType === 'form_a'
      ? 'PMAS Form A - Administrative/Leadership Questionnaire'
      : 'PMAS Form B - Faculty Questionnaire';

  const formSubtitle = viewOnly
    ? 'Review ' + (evaluation.fullName || evaluation.evaluateeName) + "'s completed evaluation."
    : 'Complete ' + (formType === 'form_a' ? 'PMAS Form A - Administrative/Leadership' : 'PMAS Form B - Faculty') + ' Questionnaire for ' + (evaluation.fullName || evaluation.evaluateeName) + '.';

  const formTypeLabel = formType === 'form_a' ? 'PMAS Form A' : 'PMAS Form B';
  const evaluationMetaItems = [
    {
      label: 'Employee Being Evaluated',
      value: evaluation.fullName || evaluation.evaluateeName || 'Unknown',
    },
    {
      label: 'Role / Position',
      value: formatRolePosition(evaluation.role || evaluation.evaluateeRole, evaluation.position || evaluation.evaluateePosition),
    },
    {
      label: 'Department',
      value: evaluation.department || 'N/A',
    },
    {
      label: 'Evaluation Form',
      value: formTypeLabel + (formType === 'form_a' ? ' - Administrative' : ' - Faculty'),
    },
    {
      label: 'Evaluation Period',
      value: evaluation.period || evaluation.periodName || 'Current Period',
    },
  ];
  function goToPreviousCategory() {
    if (activeCategoryIndex > 0) {
      goToCategory(categories[activeCategoryIndex - 1].id);
    }
  }

  function goToNextCategory() {
    if (activeCategoryIndex < categories.length - 1) {
      // Validate current category before proceeding
      const errors = validateCurrentCategory();
      if (errors && Object.keys(errors).length > 0) {
        goToCurrentCategoryMissingField(errors);
        return;
      }
      // Clear only errors for current category since they passed
      setValidationErrors((prev) => {
        const next = { ...prev };
        for (const q of activeCat.questions) {
          delete next[String(q.id)];
        }
        delete next['evidence:' + String(activeCat.id)];
        return next;
      });
      goToCategory(categories[activeCategoryIndex + 1].id);
    }
  }

  function goToReview() {
    const errors = validateAll();
    if (errors) {
      goToMissingField();
      return;
    }
    setShowReview(true);
  }

  function goBackFromReview() {
    setShowReview(false);
    if (showValidation) {
      setShowValidation(false);
    }
  }

  async function copyEvaluationSummary() {
    const name = evaluation.fullName || evaluation.evaluateeName || 'Unknown employee';
    const score = submittedAverage !== null ? submittedAverage.toFixed(2) : overallStats.finalScore.toFixed(2);
    const interpretation = submittedAverage !== null ? getScoreInterpretation(submittedAverage) : overallStats.interpretation;
    const lines = [
      `Evaluation Summary: ${name}`,
      `Final Score: ${score}/5 (${interpretation})`,
      `Form: ${formTypeLabel + (formType === 'form_a' ? ' - Administrative' : ' - Faculty')}`,
      `Period: ${evaluation.period || evaluation.periodName || 'Current Period'}`,
      '',
      'Category Breakdown:',
      ...submittedCategoryRows.map((cat) => `- ${cat.title}: ${cat.average.toFixed(2)}/5 (${cat.interpretation})`),
    ];

    try {
      await navigator.clipboard.writeText(lines.join('\n'));
      setSummaryCopied(true);
      window.setTimeout(() => setSummaryCopied(false), 1800);
    } catch (_) {
      setSummaryCopied(false);
    }
  }

  function printEvaluationSummary() {
    setSubmitError('');
    const printFrame = document.createElement('iframe');
    printFrame.setAttribute('title', 'Printable evaluation details');
    printFrame.setAttribute('aria-hidden', 'true');
    Object.assign(printFrame.style, {
      position: 'fixed',
      right: '0',
      bottom: '0',
      width: '1px',
      height: '1px',
      border: '0',
      opacity: '0',
      pointerEvents: 'none',
    });
    document.body.appendChild(printFrame);
    const reportWindow = printFrame.contentWindow;
    if (!reportWindow) {
      printFrame.remove();
      setSubmitError('Unable to prepare the printable evaluation details. Please try again.');
      return;
    }

    const employeeName = evaluation.fullName || evaluation.evaluateeName || 'Unknown employee';
    const rolePosition = formatRolePosition(
      evaluation.role || evaluation.evaluateeRole,
      evaluation.position || evaluation.evaluateePosition
    );
    const evaluationForm = formTypeLabel + (formType === 'form_a' ? ' - Administrative / Leadership' : ' - Faculty');
    const periodLabel = evaluation.period || evaluation.periodName || 'Current Period';
    const finalScore = normalizeNumber(submittedAverage !== null ? submittedAverage : overallStats.finalScore, 0);
    const evaluatorName = evaluation.evaluatorName
      || evaluation.evaluator_name
      || evaluation.reviewerName
      || evaluation.reviewer_name
      || evaluation.evaluatorRole
      || evaluatorRole
      || evaluation.assignmentTypeLabel;
    const submittedAt = evaluation.submittedAt
      || evaluation.submitted_at
      || evaluation.dateEvaluated
      || evaluation.updatedAt
      || evaluation.updated_at;
    const reportNumber = evaluation.referenceNumber || evaluation.reference_number || evaluation.id || 'N/A';
    const categoryResults = Array.isArray(evaluation.categoryResults) ? evaluation.categoryResults : [];

    const categorySummaryHtml = submittedCategoryRows.map((cat, index) => `
      <tr>
        <td class="center">${index + 1}</td>
        <td>${reportValue(cat.title)}</td>
        <td class="center">${Number(cat.weight || 0).toFixed(0)}%</td>
        <td class="center">${cat.answered}/${cat.questionCount}</td>
        <td class="center score">${cat.average.toFixed(2)}</td>
        <td>${reportValue(cat.interpretation)}</td>
      </tr>
    `).join('');

    const detailedResultsHtml = categories.map((category, categoryIndex) => {
      const submittedCategory = getSubmittedCategoryResult(category, categoryResults);
      const answers = submittedCategory?.answers && typeof submittedCategory.answers === 'object'
        ? submittedCategory.answers
        : {};
      const summary = submittedCategoryRows.find((item) => item.id === category.id);
      const questionRows = category.questions.map((question, questionIndex) => {
        const rawRating = answers[String(question.sourceId)]
          ?? answers[question.sourceId]
          ?? formData[String(question.id)];
        const rating = Number(rawRating || 0);
        const validRating = rating >= 1 && rating <= 5;
        return `
          <tr>
            <td class="center">${questionIndex + 1}</td>
            <td>${reportValue(question.text, 'Evaluation criterion')}</td>
            <td class="center score">${validRating ? rating : '—'}</td>
            <td>${validRating ? reportValue(RATING_LABELS[rating]) : 'Not recorded'}</td>
          </tr>
        `;
      }).join('');
      const evidence = submittedCategory?.behavioralEvidence
        || submittedCategory?.behavioral_evidence
        || categoryEvidence[String(category.id)]?.behavioralEvidence
        || '';

      return `
        <section class="category-detail">
          <div class="category-heading">
            <div>
              <span>Category ${categoryIndex + 1}</span>
              <h2>${reportValue(submittedCategory?.title || category.title)}</h2>
            </div>
            <div class="category-result">
              <strong>${summary ? summary.average.toFixed(2) : '0.00'} / 5.00</strong>
              <span>${reportValue(summary?.interpretation || 'No rating')}</span>
            </div>
          </div>
          ${category.description ? `<p class="category-description">${reportValue(category.description)}</p>` : ''}
          <table>
            <thead><tr><th class="number">No.</th><th>Performance Criterion</th><th class="rating">Rating</th><th>Rating Description</th></tr></thead>
            <tbody>${questionRows || '<tr><td colspan="4">No question-level results recorded.</td></tr>'}</tbody>
          </table>
          <div class="evidence"><strong>Behavioral Evidence / Remarks</strong><p>${reportValue(evidence, 'No behavioral evidence or remarks were provided.')}</p></div>
        </section>
      `;
    }).join('');

    reportWindow.document.open();
    reportWindow.document.write(`<!doctype html>
      <html lang="en">
      <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Evaluation Report - ${reportValue(employeeName)}</title>
        <style>
          @page { size: A4 portrait; margin: 14mm; }
          * { box-sizing: border-box; }
          body { margin: 0; color: #17211d; font: 11px/1.45 Arial, Helvetica, sans-serif; background: #fff; }
          .report { max-width: 900px; margin: 0 auto; }
          .report-header { display: grid; grid-template-columns: 1fr auto; gap: 20px; align-items: start; padding-bottom: 14px; border-bottom: 3px solid #137a52; }
          .institution { color: #137a52; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
          h1 { margin: 4px 0 2px; font-size: 24px; line-height: 1.15; }
          .report-subtitle { margin: 0; color: #53645e; font-size: 12px; }
          .report-ref { min-width: 170px; border: 1px solid #cfe2d8; border-radius: 8px; padding: 9px 11px; background: #f3faf6; }
          .report-ref span, .meta span, .summary-card span, .category-heading > div > span { display: block; color: #63736c; font-size: 9px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
          .report-ref strong { display: block; margin-top: 2px; color: #173b2e; }
          .meta-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0; margin: 16px 0; border: 1px solid #d8e4de; border-radius: 9px; overflow: hidden; }
          .meta { min-height: 55px; padding: 9px 11px; border-right: 1px solid #d8e4de; border-bottom: 1px solid #d8e4de; }
          .meta:nth-child(2n) { border-right: 0; }
          .meta strong { display: block; margin-top: 3px; font-size: 12px; }
          .summary-band { display: grid; grid-template-columns: 150px 1fr; gap: 12px; margin: 14px 0 18px; }
          .summary-card { display: grid; align-content: center; min-height: 105px; border-radius: 10px; padding: 14px; background: #137a52; color: #fff; }
          .summary-card span { color: #d9f5e8; }
          .summary-card strong { margin: 3px 0; font-size: 28px; }
          .summary-card p { margin: 0; font-weight: 700; }
          .report-note { border: 1px solid #d8e4de; border-radius: 10px; padding: 12px 14px; background: #f8fbf9; }
          .report-note h2, .section-title { margin: 0 0 5px; color: #173b2e; font-size: 14px; }
          .report-note p { margin: 0; color: #53645e; }
          table { width: 100%; border-collapse: collapse; margin-top: 8px; }
          th, td { border: 1px solid #cedbd4; padding: 7px 8px; text-align: left; vertical-align: top; }
          th { background: #e9f6ef; color: #173b2e; font-size: 9px; letter-spacing: .035em; text-transform: uppercase; }
          td.center, th.number, th.rating { text-align: center; }
          .score { color: #106c49; font-weight: 800; }
          .category-detail { break-inside: avoid; margin-top: 20px; padding-top: 4px; }
          .category-heading { display: flex; justify-content: space-between; gap: 14px; align-items: end; border-left: 4px solid #137a52; padding: 5px 0 5px 10px; }
          .category-heading h2 { margin: 2px 0 0; font-size: 14px; }
          .category-result { text-align: right; }
          .category-result strong, .category-result span { display: block; }
          .category-result strong { color: #106c49; font-size: 14px; }
          .category-description { margin: 7px 0; color: #586861; font-style: italic; }
          .evidence { margin-top: 8px; border: 1px solid #d8e4de; border-radius: 7px; padding: 8px 10px; background: #fafcfb; }
          .evidence p { margin: 3px 0 0; white-space: pre-wrap; }
          .rating-scale { break-inside: avoid; margin-top: 22px; border-top: 2px solid #d8e4de; padding-top: 12px; }
          .rating-list { display: grid; grid-template-columns: repeat(5, 1fr); gap: 5px; }
          .rating-list div { border: 1px solid #d8e4de; border-radius: 6px; padding: 6px; text-align: center; }
          .rating-list strong { display: block; color: #106c49; font-size: 14px; }
          .signatures { display: grid; grid-template-columns: repeat(2, 1fr); gap: 45px; margin-top: 45px; break-inside: avoid; }
          .signature { border-top: 1px solid #27332e; padding-top: 5px; text-align: center; }
          .signature strong, .signature span { display: block; }
          .signature span { color: #66736e; font-size: 9px; }
          .footer { margin-top: 22px; border-top: 1px solid #d8e4de; padding-top: 7px; color: #718079; font-size: 9px; text-align: center; }
          .print-actions { position: sticky; top: 0; display: flex; justify-content: flex-end; gap: 8px; margin: 0 0 14px; padding: 10px; background: rgba(255,255,255,.96); border-bottom: 1px solid #d8e4de; }
          .print-actions button { border: 1px solid #137a52; border-radius: 7px; padding: 8px 13px; background: #137a52; color: #fff; font-weight: 700; cursor: pointer; }
          .print-actions button.secondary { background: #fff; color: #137a52; }
          @media print { .print-actions { display: none; } .report { max-width: none; } }
        </style>
      </head>
      <body>
        <div class="print-actions"><button id="close-report" class="secondary" type="button">Close</button><button id="print-report" type="button">Print Report</button></div>
        <main class="report">
          <header class="report-header">
            <div><div class="institution">APPRAISIA • Performance Management and Appraisal System</div><h1>Individual Evaluation Report</h1><p class="report-subtitle">Official detailed record of a completed performance evaluation</p></div>
            <div class="report-ref"><span>Report Reference</span><strong>${reportValue(reportNumber)}</strong><span style="margin-top:7px">Generated</span><strong>${formatReportDate(new Date())}</strong></div>
          </header>
          <section class="meta-grid">
            <div class="meta"><span>Employee Being Evaluated</span><strong>${reportValue(employeeName)}</strong></div>
            <div class="meta"><span>Role / Position</span><strong>${reportValue(rolePosition)}</strong></div>
            <div class="meta"><span>Department</span><strong>${reportValue(evaluation.department)}</strong></div>
            <div class="meta"><span>Program</span><strong>${reportValue(evaluation.program || evaluation.programCode || evaluation.program_code)}</strong></div>
            <div class="meta"><span>Evaluation Form</span><strong>${reportValue(evaluationForm)}</strong></div>
            <div class="meta"><span>Evaluation Period</span><strong>${reportValue(periodLabel)}</strong></div>
            <div class="meta"><span>Evaluator / Reviewer</span><strong>${reportValue(evaluatorName, 'Recorded evaluator')}</strong></div>
            <div class="meta"><span>Date Submitted</span><strong>${formatReportDate(submittedAt)}</strong></div>
          </section>
          <section class="summary-band">
            <div class="summary-card"><span>Overall Result</span><strong>${finalScore.toFixed(2)} / 5.00</strong><p>${reportValue(getScoreInterpretation(finalScore))}</p></div>
            <div class="report-note"><h2>Report Scope</h2><p>This report contains the official evaluation profile, category results, individual criterion ratings, rating descriptions, and submitted behavioral evidence. Scores reflect the recorded responses for the selected appraisal period.</p></div>
          </section>
          <section><h2 class="section-title">Category Performance Summary</h2><table><thead><tr><th class="number">No.</th><th>Category</th><th class="rating">Weight</th><th class="rating">Answered</th><th class="rating">Average</th><th>Interpretation</th></tr></thead><tbody>${categorySummaryHtml}</tbody></table></section>
          ${detailedResultsHtml}
          <section class="rating-scale"><h2 class="section-title">Rating Scale</h2><div class="rating-list">${Object.entries(RATING_LABELS).reverse().map(([score, label]) => `<div><strong>${score}</strong><span>${reportValue(label)}</span></div>`).join('')}</div></section>
          <section class="signatures"><div class="signature"><strong>${reportValue(employeeName)}</strong><span>Employee / Evaluatee Signature and Date</span></div><div class="signature"><strong>${reportValue(evaluatorName, 'Evaluator / Reviewer')}</strong><span>Evaluator / Reviewer Signature and Date</span></div></section>
          <footer class="footer">APPRAISIA Individual Evaluation Report • Generated from the submitted evaluation record • Page printed on ${formatReportDate(new Date())}</footer>
        </main>
      </body>
      </html>`);
    reportWindow.document.close();
    let cleanedUp = false;
    const removePrintFrame = () => {
      if (cleanedUp) return;
      cleanedUp = true;
      printFrame.remove();
    };
    const launchPrint = () => {
      try {
        reportWindow.focus();
        reportWindow.print();
      } catch (_) {
        removePrintFrame();
        setSubmitError('The browser could not open the print dialog. Please try again.');
      }
    };
    reportWindow.addEventListener('afterprint', removePrintFrame, { once: true });

    // Printing from a same-page frame avoids popup blockers on mobile and
    // desktop browsers. Wait for layout and font readiness first.
    const ready = reportWindow.document.fonts?.ready || Promise.resolve();
    ready.then(() => window.setTimeout(launchPrint, 150));
    window.setTimeout(removePrintFrame, 60000);
  }

  // Set question ref callback
  function setQuestionRef(key) {
    return (el) => {
      questionRefs.current[key] = el;
    };
  }

  return (
    <div className="dipascaf-modal">
      <div className="dipascaf-modal-panel evaluation-form-modal">
        <div className="dipascaf-modal-header">
          <div className="dipascaf-modal-header-text">
            <h2>{formTitle}</h2>
            <p>{formSubtitle}</p>
          </div>
          <button type="button" className="dipascaf-modal-close modal-icon-close" onClick={closeWithDraftSave} aria-label="Close evaluation form"><X size={18} /></button>
        </div>
        {!(viewOnly && submittedAverage !== null && !submittedResults) && (
          <div className="evaluation-form-meta">
            {evaluationMetaItems.map((item) => (
              <div key={item.label}>
                <span>{item.label}</span>
                <strong>{item.value}</strong>
              </div>
            ))}
          </div>
        )}

        {fetchError && (
          <div className="notice error" style={{ padding: '0.75rem 1rem', background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: '0.5rem', color: '#991b1b', marginBottom: '1rem' }}>
            {fetchError}
          </div>
        )}

        {loading && (
          <div className="dipascaf-empty" style={{ padding: '2rem', textAlign: 'center' }}>
            Loading questionnaire from database...
          </div>
        )}

        {!loading && categories.length === 0 && !fetchError && (
          <div className="notice warning" style={{ padding: '1rem', background: '#fef3c7', border: '1px solid #f59e0b', borderRadius: '0.5rem', color: '#92400e' }}>
            No categories found. The admin may not have published the questionnaire yet.
          </div>
        )}

        {submitError && (
          <div className="notice error" style={{ padding: '0.75rem 1rem', background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: '0.5rem', color: '#991b1b', marginBottom: '1rem' }}>
            {submitError}
          </div>
        )}

        {!loading && categories.length > 0 && (!viewOnly || submittedResults) && (
          <form className="admin-form evaluation-form" onSubmit={handleSubmit} ref={formRef}>
            {!submittedResults && (
              <>
                <div className="form-category-header">
                  <strong>Categories</strong>
                  <small>Select a category to view and rate its questions.</small>
                </div>
                <div className="form-category-nav">
                  <label className="form-category-select-label" htmlFor="evaluation-category-select">
                    Category
                  </label>
                  <select
                    id="evaluation-category-select"
                    className="form-category-select"
                    value={activeCategory ?? categories[0]?.id ?? ''}
                    onChange={(event) => {
                      goToCategory(event.target.value, { clearValidation: true });
                    }}
                  >
                    {categories.map((cat) => (
                      <option key={cat.id} value={cat.id}>
                        {cat.title}
                      </option>
                    ))}
                  </select>
                </div>
              </>
            )}

            {!viewOnly && !submittedResults && !showReview && (
              <div className="form-category-progress">
                <div className="form-progress-copy">
                  <strong>Category {activeCategoryNumber} of {categories.length}</strong>
                  <span>{completionPercent}% Completed</span>
                </div>
                <div className="form-progress-track" aria-label={`${completionPercent}% completed`}>
                  <span style={{ width: `${completionPercent}%` }} />
                </div>
              </div>
            )}

            {!viewOnly && !submittedResults && showReview && renderReviewSummary()}

            {!viewOnly && !submittedResults && !showReview && activeCat && (
              <div className="form-scroll-questions">
                <div className="form-category-panel">
                  {renderSharedRatingGuide()}
                  <h3 style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    {activeCat.title}
                    <span style={{ fontSize: '0.75rem', fontWeight: 500, color: '#6b7280' }}>
                      Weight: {activeCat.weight}%
                    </span>
                  </h3>
                  {activeCat.description && <p style={{ fontSize: '0.875rem', color: '#6b7280' }}>{activeCat.description}</p>}

                  <div className="form-questions-list">
                    {activeCat.questions.length === 0 ? (
                      <div className="notice info" style={{ padding: '1rem', background: '#eff6ff', border: '1px solid #93c5fd', borderRadius: '0.5rem', color: '#1e40af', fontWeight: 500 }}>
                        No questions found for this category.
                      </div>
                    ) : (
                      activeCat.questions.map((q, idx) => renderQuestion(q, idx, viewOnly || submittedResults !== null))
                    )}
                  </div>
                  {activeCat.questions.length > 0 && renderCategoryResult(activeCat, viewOnly || submittedResults !== null)}
                </div>
              </div>
            )}

            {!viewOnly && !submittedResults && !showReview && (
              <div className="form-submit-row">
                <div className="form-auto-save-indicator">
                  {autoSaveStatus === 'saving' && <span className="auto-save-saving">Auto-saving...</span>}
                  {autoSaveStatus === 'saved' && <span className="auto-save-saved">Draft saved</span>}
                  {lastSaved && autoSaveStatus !== 'saving' && autoSaveStatus !== 'saved' && (
                    <span className="auto-save-idle">
                      Saved {lastSaved.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                    </span>
                  )}
                </div>
                <div className="form-submit-buttons">
                  <button
                    type="button"
                    className="dipascaf-evaluate-btn evaluation-nav-btn secondary"
                    onClick={goToPreviousCategory}
                    disabled={activeCategoryIndex <= 0}
                  >
                    Previous
                  </button>
                  {activeCategoryIndex < categories.length - 1 ? (
                    <button
                      type="button"
                      className="dipascaf-evaluate-btn evaluation-nav-btn secondary"
                      onClick={goToNextCategory}
                    >
                      Next
                    </button>
                  ) : (
                    <button
                      type="button"
                      className="dipascaf-evaluate-btn evaluation-nav-btn primary review-nav-btn"
                      onClick={goToReview}
                    >
                      <CheckCircle2 size={16} /> Review &amp; Submit
                    </button>
                  )}
                  <button
                    type="submit"
                    className="dipascaf-evaluate-btn evaluation-submit-btn"
                    disabled={submitting}
                  >
                    {submitting ? 'Submitting...' : 'Submit Evaluation'}
                  </button>
                </div>
              </div>
            )}

            {submittedResults && renderResults()}

            {!viewOnly && showReview && !submittedResults && (
              <div className="form-auto-save-indicator" style={{ padding: '8px 0' }}>
                {autoSaveStatus === 'saving' && <span className="auto-save-saving">Auto-saving...</span>}
                {autoSaveStatus === 'saved' && <span className="auto-save-saved">Draft saved</span>}
                {lastSaved && autoSaveStatus !== 'saving' && autoSaveStatus !== 'saved' && (
                  <span className="auto-save-idle">
                    Saved {lastSaved.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                  </span>
                )}
              </div>
            )}
          </form>
        )}

        {viewOnly && submittedAverage !== null && !submittedResults && (
          <section className="evaluation-view-summary">
            <div className="evaluation-view-score">
              <div className="evaluation-view-credentials" aria-label="Evaluation credentials">
                {evaluationMetaItems.map((item) => (
                  <article key={item.label}>
                    <span>{item.label}</span>
                    <strong>{item.value}</strong>
                  </article>
                ))}
              </div>
              <div className="evaluation-view-score-result">
                <span>Evaluation Summary</span>
                <strong>{submittedAverage.toFixed(2)}<small>/5</small></strong>
                <p>{getScoreInterpretation(submittedAverage)}</p>
              </div>
            </div>
            <div className="evaluation-view-output">
              <div className="evaluation-view-output-head">
                <div>
                  <strong>Category Breakdown</strong>
                  <span>{submittedCategoryRows.length} rated categor{submittedCategoryRows.length === 1 ? 'y' : 'ies'}</span>
                </div>
                <div className="evaluation-view-actions">
                  <button type="button" onClick={copyEvaluationSummary}>
                    <ClipboardCheck size={16} /> {summaryCopied ? 'Copied' : 'Copy'}
                  </button>
                  <button type="button" onClick={printEvaluationSummary}>
                    <Printer size={18} strokeWidth={2.5} /> Print
                  </button>
                </div>
              </div>
              <div className="evaluation-view-category-grid">
                {submittedCategoryRows.map((cat) => (
                  <article key={cat.id} className="evaluation-view-category-card">
                    <div>
                      <strong title={cat.title}>{cat.title}</strong>
                      <span>{cat.answered}/{cat.questionCount} questions</span>
                    </div>
                    <p>{cat.average.toFixed(2)} <small>/5</small></p>
                    <em>{cat.interpretation}</em>
                  </article>
                ))}
              </div>
            </div>
          </section>
        )}

        {viewOnly && submittedAverage === null && (
          <div className="notice info" style={{ padding: '1rem', background: '#eff6ff', border: '1px solid #93c5fd', borderRadius: '0.5rem', color: '#1e40af', marginTop: '1rem' }}>
            This evaluation is pending. Admin/HR can monitor progress here without editing the scoring form.
          </div>
        )}
      </div>
    </div>
  );
}
