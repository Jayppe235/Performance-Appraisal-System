import { useCallback, useEffect, useMemo, useState } from "react";
import { createPortal } from "react-dom";
import {
  Check,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Loader2,
  Pencil,
  Save,
  Search,
  ShieldAlert,
  UserMinus,
  UserPlus,
  X,
} from "lucide-react";
import apiFetch from "../../data/api.js";
import { useEvaluationPeriod } from "../../contexts/EvaluationPeriodContext.jsx";
import { addToast } from "../common/Toast.jsx";
import { confirmProceed } from "../common/ConfirmationModal.jsx";
import useLiveRefresh from "../../hooks/useLiveRefresh.js";

const reasons = [
  ["resignation", "Resignation"],
  ["retirement", "Retirement"],
  ["transfer", "Transfer"],
  ["other", "Other"],
];

export default function PeriodParticipantsPanel() {
  const { selectedPeriodId, selectedPeriod } = useEvaluationPeriod();
  const [rows, setRows] = useState([]);
  const [options, setOptions] = useState({ departments: [], programs: [] });
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState("");
  const [departmentFilter, setDepartmentFilter] = useState("all");
  const [roleFilter, setRoleFilter] = useState("all");
  const [programFilter, setProgramFilter] = useState("all");
  const [periodMeta, setPeriodMeta] = useState(null);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(8);
  const [target, setTarget] = useState(null);
  const [reason, setReason] = useState("leave");
  const [notes, setNotes] = useState("");
  const [editTarget, setEditTarget] = useState(null);
  const [assignment, setAssignment] = useState({
    role: "teacher",
    department_id: "",
    program_ids: [],
    primary_program_id: "",
    lead_program_ids: [],
    allow_co_head: false,
    co_head_reason: "",
    acting_dean_reason: "",
    replaced_dean_action: "faculty",
  });
  const [assignmentConflicts, setAssignmentConflicts] = useState([]);

  const load = useCallback(async () => {
    if (!selectedPeriodId) {
      setRows([]);
      return;
    }
    setLoading(true);
    try {
      const data = await apiFetch(
        `/api/evaluation-period-participation.php?evaluation_period_id=${encodeURIComponent(selectedPeriodId)}`,
      );
      if (!data.ok)
        throw new Error(data.message || "Unable to load period participants.");
      setRows(data.participants || []);
      setOptions(data.options || { departments: [], programs: [] });
      setPeriodMeta(data.period || null);
    } catch (error) {
      addToast({ type: "error", text: error.message });
    } finally {
      setLoading(false);
    }
  }, [selectedPeriodId]);

  useLiveRefresh(load, [selectedPeriodId], { intervalMs: 10000 });

  const departments = useMemo(
    () =>
      Array.from(
        new Set(
          rows
            .map((row) => String(row.department || "").trim())
            .filter(Boolean),
        ),
      ).sort((a, b) => a.localeCompare(b)),
    [rows],
  );
  const roles = useMemo(
    () => {
      const values = new Set(
        rows
          .map((row) =>
            Number(row.is_acting_dean) === 1
              ? "acting_dean"
              : String(row.role || "").trim(),
          )
          .filter(Boolean),
      );
      return ["teacher", "program_head", "dean", "acting_dean"].filter((role) =>
        values.has(role),
      );
    },
    [rows],
  );
  const filteredProgramOptions = useMemo(
    () =>
      options.programs.filter(
        (program) =>
          departmentFilter === "all" ||
          String(program.department_name || "") === departmentFilter,
      ),
    [departmentFilter, options.programs],
  );

  const filtered = useMemo(
    () =>
      rows.filter((row) => {
        const matchesDepartment =
          departmentFilter === "all" ||
          String(row.department || "") === departmentFilter;
        const rowRole =
          Number(row.is_acting_dean) === 1
            ? "acting_dean"
            : String(row.role || "");
        const matchesRole = roleFilter === "all" || rowRole === roleFilter;
        const matchesProgram =
          programFilter === "all" ||
          String(row.program || "") === programFilter ||
          row.programs?.some((item) => String(item.program_code) === programFilter);
        const programText = Array.isArray(row.programs)
          ? row.programs
              .map((item) => `${item.program_code} ${item.program_name}`)
              .join(" ")
          : "";
        const haystack =
          `${row.full_name} ${row.user_code} ${row.department} ${row.program} ${programText} ${row.role}`.toLowerCase();
        return (
          matchesDepartment &&
          matchesRole &&
          matchesProgram &&
          haystack.includes(search.trim().toLowerCase())
        );
      }),
    [rows, search, departmentFilter, roleFilter, programFilter],
  );
  const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
  const pagedRows = useMemo(
    () => filtered.slice((page - 1) * pageSize, page * pageSize),
    [filtered, page, pageSize],
  );

  useEffect(() => {
    setPage(1);
  }, [selectedPeriodId, search, departmentFilter, programFilter, roleFilter, pageSize]);

  useEffect(() => {
    setPage((current) => Math.min(current, pageCount));
  }, [pageCount]);

  const workflowAction = async (action) => {
    const confirmed = await confirmProceed({
      title: action === "finalize" ? "Finalize period participants?" : "Reopen period participants?",
      message: action === "finalize"
        ? "Role, department, program, participation, and employment snapshots will be locked before Peer Assignments."
        : "Participant editing will be restored and existing peer validation will be cleared.",
      confirmText: action === "finalize" ? "Finalize Participants" : "Reopen Participants",
    });
    if (!confirmed) return;
    setSaving(true);
    try {
      const data = await apiFetch("/api/evaluation-period-participation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action, evaluation_period_id: Number(selectedPeriodId) }),
      });
      addToast({ type: "success", text: data.message });
      await load();
    } catch (error) {
      addToast({ type: "error", text: error.message });
    } finally {
      setSaving(false);
    }
  };

  const setEmploymentStatus = async (row, employmentStatus) => {
    setSaving(true);
    try {
      const data = await apiFetch("/api/evaluation-period-participation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "employment_status",
          evaluation_period_id: Number(selectedPeriodId),
          user_id: Number(row.user_id),
          employment_status: employmentStatus,
        }),
      });
      addToast({ type: "success", text: data.message });
      await load();
    } catch (error) {
      addToast({ type: "error", text: error.message });
    } finally {
      setSaving(false);
    }
  };

  const candidateAction = async (action, row) => {
    if (action === "remove") {
      const confirmed = await confirmProceed({
        message: `Remove ${row.full_name} from this unfinalized period candidate list?`,
        confirmText: "Remove Candidate",
      });
      if (!confirmed) return;
    }
    setSaving(true);
    try {
      const data = await apiFetch("/api/evaluation-period-participation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: action === "add" ? "seed" : "remove",
          evaluation_period_id: Number(selectedPeriodId),
          user_id: Number(row.user_id),
        }),
      });
      addToast({ type: "success", text: data.message });
      await load();
    } catch (error) {
      addToast({ type: "error", text: error.message });
    } finally {
      setSaving(false);
    }
  };

  const exclude = async (event) => {
    event.preventDefault();
    if (reason === "other" && !notes.trim()) {
      addToast({ type: "error", text: "Add notes for the Other reason." });
      return;
    }
    setSaving(true);
    try {
      const data = await apiFetch("/api/evaluation-period-participation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "exclude",
          evaluation_period_id: Number(selectedPeriodId),
          user_id: Number(target.user_id),
          reason,
          notes: notes.trim(),
        }),
      });
      if (!data.ok)
        throw new Error(data.message || "Unable to exclude faculty member.");
      addToast({ type: "success", text: data.message });
      setTarget(null);
      setNotes("");
      setReason("leave");
      await load();
    } catch (error) {
      addToast({ type: "error", text: error.message });
    } finally {
      setSaving(false);
    }
  };

  const include = async (row) => {
    const confirmed = await confirmProceed({
      message: `Re-include ${row.full_name} in ${selectedPeriod?.period_name || "this period"}? Safe non-submitted requirements will become active again.`,
      confirmText: "Re-include Faculty",
    });
    if (!confirmed) return;
    setSaving(true);
    try {
      const data = await apiFetch("/api/evaluation-period-participation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "include",
          evaluation_period_id: Number(selectedPeriodId),
          user_id: Number(row.user_id),
        }),
      });
      if (!data.ok)
        throw new Error(data.message || "Unable to re-include faculty member.");
      addToast({ type: "success", text: data.message });
      await load();
    } catch (error) {
      addToast({ type: "error", text: error.message });
    } finally {
      setSaving(false);
    }
  };

  const beginEdit = (row) => {
    const department = options.departments.find(
      (item) =>
        Number(item.id) === Number(row.department_id) ||
        item.department_name === row.department ||
        item.department_code === row.department,
    );
    const program = options.programs.find(
      (item) =>
        Number(item.id) === Number(row.program_id) ||
        (String(item.program_code).toUpperCase() ===
          String(row.program).toUpperCase() &&
          (!department ||
            Number(item.department_id) === Number(department.id))),
    );
    const assignedPrograms =
      Array.isArray(row.programs) && row.programs.length
        ? row.programs
        : program
          ? [{ program_id: program.id, is_primary: 1, is_lead_evaluator: 1 }]
          : [];
    const programIds = assignedPrograms.map((item) => String(item.program_id));
    setAssignment({
      role: ["program_head", "dean"].includes(row.role) ? row.role : "teacher",
      department_id: department?.id ? String(department.id) : "",
      program_ids: programIds,
      primary_program_id: String(
        assignedPrograms.find((item) => Number(item.is_primary) === 1)
          ?.program_id ||
          programIds[0] ||
          "",
      ),
      lead_program_ids: assignedPrograms
        .filter((item) => Number(item.is_lead_evaluator) === 1)
        .map((item) => String(item.program_id)),
      allow_co_head: false,
      co_head_reason: "",
      acting_dean_reason: row.dean_authorization_reason || "",
      replaced_dean_action: "faculty",
    });
    setAssignmentConflicts([]);
    setEditTarget(row);
  };

  const availablePrograms = useMemo(
    () =>
      options.programs.filter(
        (program) =>
          !assignment.department_id ||
          Number(program.department_id) === Number(assignment.department_id),
      ),
    [assignment.department_id, options.programs],
  );

  const toggleAssignedProgram = (programId) => {
    const id = String(programId);
    setAssignment((current) => {
      const selected = current.program_ids.includes(id);
      const program_ids = selected
        ? current.program_ids.filter((item) => item !== id)
        : [...current.program_ids, id];
      return {
        ...current,
        program_ids,
        primary_program_id:
          current.primary_program_id === id
            ? program_ids[0] || ""
            : current.primary_program_id || id,
        lead_program_ids: selected
          ? current.lead_program_ids.filter((item) => item !== id)
          : [...current.lead_program_ids, id],
      };
    });
    setAssignmentConflicts([]);
  };

  const saveAssignment = async (event) => {
    event.preventDefault();
    if (
      !assignment.department_id ||
      (assignment.role === "program_head" &&
        (assignment.program_ids.length === 0 || !assignment.primary_program_id))
    ) {
      addToast({
        type: "error",
        text: "Select a department. Program Heads must also have at least one program and a primary program.",
      });
      return;
    }
    if (assignment.allow_co_head && !assignment.co_head_reason.trim()) {
      addToast({
        type: "error",
        text: "Enter the administrative reason for the co-head arrangement.",
      });
      return;
    }
    if (assignment.role === "dean" && !assignment.acting_dean_reason.trim()) {
      addToast({
        type: "error",
        text: "Enter the administrative reason for the Acting Dean assignment.",
      });
      return;
    }
    if (assignment.role === "dean") {
      const confirmed = await confirmProceed({
        title: "Promote to Acting Dean for this period?",
        message: `${editTarget.full_name} will receive Dean authority only for ${selectedPeriod?.period_name}. The permanent account role and historical periods will not change.`,
        confirmText: "Promote to Acting Dean",
      });
      if (!confirmed) return;
    }
    if (assignment.allow_co_head) {
      const confirmed = await confirmProceed({
        title: "Authorize co-head arrangement?",
        message: `This creates a period-specific co-head arrangement for ${editTarget.full_name}. The authorization and reason will be recorded.`,
        confirmText: "Authorize Co-head",
      });
      if (!confirmed) return;
    }
    setSaving(true);
    try {
      const data = await apiFetch("/api/evaluation-period-participation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "update_assignment",
          evaluation_period_id: Number(selectedPeriodId),
          user_id: Number(editTarget.user_id),
          role: assignment.role,
          department_id: Number(assignment.department_id),
          program_ids: assignment.program_ids.map(Number),
          primary_program_id: Number(assignment.primary_program_id),
          lead_program_ids: assignment.lead_program_ids.map(Number),
          allow_co_head: assignment.allow_co_head,
          co_head_reason: assignment.co_head_reason.trim(),
          acting_dean_reason: assignment.acting_dean_reason.trim(),
          replaced_dean_action: assignment.replaced_dean_action,
          confirm_dean_replacement: assignment.role === "dean",
        }),
      });
      addToast({ type: "success", text: data.message });
      setEditTarget(null);
      await load();
    } catch (error) {
      if (error.code === "program_head_conflict") {
        const conflicts = error.payload?.conflicts || [];
        const conflictIds = new Set(
          conflicts.map((item) => String(item.program_id)),
        );
        setAssignmentConflicts(conflicts);
        setAssignment((current) => ({
          ...current,
          allow_co_head: false,
          lead_program_ids: current.lead_program_ids.filter(
            (id) => !conflictIds.has(String(id)),
          ),
        }));
        addToast({
          type: "error",
          text: "Review the conflicting Program Head assignments below.",
        });
        return;
      }
      addToast({ type: "error", text: error.message });
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="period-participants-panel">
      <header>
        <div>
          <p className="eyebrow">Period Participation</p>
          <h2>Evaluation Period Participants</h2>
          <p>
            Manage Faculty, Program Head, Dean, and Acting Dean participation
            without changing permanent accounts or historical records.
          </p>
        </div>
        <div className="period-participant-period">
          <span>Selected Period</span>
          <strong>{selectedPeriod?.period_name || "Select a period"}</strong>
        </div>
      </header>
      <div className="period-participant-tools">
        <label className="period-participant-search">
          <Search size={17} />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search participant, code, department, or program"
          />
        </label>
        <label className="period-participant-filter">
          <span>Department</span>
          <select
            value={departmentFilter}
            onChange={(e) => {
              setDepartmentFilter(e.target.value);
              setProgramFilter("all");
            }}
          >
            <option value="all">All departments</option>
            {departments.map((department) => (
              <option key={department} value={department}>
                {department}
              </option>
            ))}
          </select>
        </label>
        <label className="period-participant-filter">
          <span>Program</span>
          <select
            value={programFilter}
            onChange={(e) => setProgramFilter(e.target.value)}
            disabled={departmentFilter !== "all" && filteredProgramOptions.length === 0}
          >
            <option value="all">
              {departmentFilter === "all" ? "All programs" : `All ${departmentFilter} programs`}
            </option>
            {filteredProgramOptions.map((program) => (
              <option key={program.id} value={program.program_code}>
                {program.program_code} — {program.program_name}
              </option>
            ))}
          </select>
        </label>
        <label className="period-participant-filter">
          <span>Role</span>
          <select
            value={roleFilter}
            onChange={(e) => setRoleFilter(e.target.value)}
          >
            <option value="all">All roles</option>
            {roles.map((role) => (
              <option key={role} value={role}>
                {role === "acting_dean"
                  ? "Acting Dean"
                  : role === "dean"
                    ? "Dean"
                  : role === "program_head"
                    ? "Program Head"
                    : role === "teacher"
                      ? "Faculty"
                      : role.replaceAll("_", " ")}
              </option>
            ))}
          </select>
        </label>
        <div className="period-participant-tool-actions">
          <span>{filtered.length} of {rows.length} accounts</span>
          <button type="button" onClick={load} disabled={loading}>
            {loading ? (
              <Loader2 size={16} className="animate-spin" />
            ) : (
              <CheckCircle2 size={16} />
            )}{" "}
            Refresh
          </button>
          <button type="button" disabled={saving || !selectedPeriodId} onClick={() => workflowAction(periodMeta?.participants_finalized ? "reopen" : "finalize")}>
            {periodMeta?.participants_finalized ? "Reopen Participants" : "Finalize Participants"}
          </button>
        </div>
      </div>
      {!selectedPeriodId ? (
        <div className="period-participant-empty">
          Select an evaluation period to manage participation.
        </div>
      ) : loading && !rows.length ? (
        <div className="period-participant-empty">
          <Loader2 className="animate-spin" /> Loading participants...
        </div>
      ) : (
        <div className="period-participant-table-wrap">
          <table>
            <thead>
              <tr>
                <th>Participant</th>
                <th>Period Assignment</th>
                <th>Account</th>
                <th>Period History</th>
                <th>Activity</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {pagedRows.map((row) => (
                <tr
                  key={`${row.user_id}-${row.faculty_id}`}
                  className={Number(row.is_new_account_for_period) === 1 ? "is-new-account" : ""}
                >
                  <td>
                    <strong>{row.full_name}</strong>
                    <small>Code {row.user_code}</small>
                  </td>
                  <td>
                    <span>
                      {row.role === "dean"
                        ? Number(row.is_acting_dean) === 1
                          ? "Acting Dean"
                          : "Dean"
                        : row.role === "program_head"
                          ? "Program Head"
                          : "Faculty"}
                    </span>
                    {row.role === "program_head" && row.programs?.length ? (
                      <div className="period-program-chips">
                        {row.programs.map((program) => (
                          <span
                            key={program.program_id}
                            className={
                              Number(program.co_head_authorized) === 1
                                ? "co-head"
                                : ""
                            }
                          >
                            <b>{program.program_code}</b>
                            {Number(program.is_lead_evaluator) === 1 && (
                              <em>Lead</em>
                            )}
                            {Number(program.co_head_authorized) === 1 && (
                              <em>Co-head</em>
                            )}
                          </span>
                        ))}
                      </div>
                    ) : (
                      <small>
                        {[row.department, row.program]
                          .filter(Boolean)
                          .join(" • ") || "Unassigned"}
                      </small>
                    )}
                    <small className="period-assignment-source">
                      {row.assignment_source === "admin" ||
                      row.dean_assignment_source === "admin"
                        ? "Period Override"
                        : "Master Assignment"}
                      {Number(row.is_acting_dean) === 1 ? " • Acting Dean" : ""}
                      {row.work_status === "no_assignments"
                        ? " • No Assignments"
                        : ""}
                      {Number(row.needs_review) === 1 ? " • Review needed" : ""}
                    </small>
                  </td>
                  <td>
                    <span
                      className={`period-status account-${Number(row.is_active) === 1 ? "active" : "inactive"}`}
                    >
                      {Number(row.is_active) === 1 ? "Active" : "Inactive"}
                    </span>
                  </td>
                  <td>
                    <div className="period-history-count" aria-label={`${Number(row.assigned_period_count) || 0} assigned periods`}>
                      <strong>{Number(row.assigned_period_count) || 0}</strong>
                      <span>
                        <small>Period history</small>
                        {Number(row.assigned_period_count) === 1 ? "1 period" : `${Number(row.assigned_period_count) || 0} periods`}
                      </span>
                    </div>
                    {Number(row.is_new_account_for_period) === 1 && (
                      <span className="period-new-account-badge">New Account</span>
                    )}
                  </td>
                  <td>
                    <div className="period-activity-summary" aria-label="Evaluation activity">
                      <span><strong>{Number(row.submitted_count) || 0}</strong><small>Submitted</small></span>
                      <span><strong>{Number(row.open_count) || 0}</strong><small>Open</small></span>
                      <span><strong>{Number(row.not_required_count) || 0}</strong><small>Not required</small></span>
                    </div>
                  </td>
                  <td>
                    <div className="period-participant-row-actions">
                      {!Number(row.is_configured) ? (
                        <button type="button" className="include" disabled={saving || periodMeta?.participants_finalized || !row.start_evaluation_period_id} onClick={() => candidateAction("add", row)}>
                          <UserPlus size={15} /> Add
                        </button>
                      ) : row.participation_status === "excluded" ? (
                        <button type="button" className="exclude is-excluded" disabled>
                          <UserMinus size={15} /> Excluded
                        </button>
                      ) : (
                        <button type="button" className="exclude" disabled={saving || periodMeta?.participants_finalized} onClick={() => setTarget(row)}>
                          <UserMinus size={15} /> Exclude
                        </button>
                      )}
                      <button
                        type="button"
                        className="edit"
                        disabled={saving || periodMeta?.participants_finalized}
                        onClick={() => beginEdit(row)}
                      >
                        <Pencil size={15} /> Edit Assignment
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {!filtered.length && (
                <tr>
                  <td colSpan="6" className="period-participant-empty">
                    No participants match the current filters.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
          <footer className="period-participant-pagination">
            <div>
              <strong>
                {filtered.length === 0 ? 0 : (page - 1) * pageSize + 1}–{Math.min(page * pageSize, filtered.length)}
              </strong>
              <span>of {filtered.length} accounts</span>
            </div>
            <label>
              Rows per page
              <select value={pageSize} onChange={(event) => setPageSize(Number(event.target.value))}>
                <option value="5">5</option>
                <option value="8">8</option>
                <option value="10">10</option>
                <option value="15">15</option>
              </select>
            </label>
            <nav aria-label="Participant pages">
              <button type="button" onClick={() => setPage((current) => Math.max(1, current - 1))} disabled={page <= 1}>
                <ChevronLeft size={16} /> Previous
              </button>
              <span>Page <strong>{page}</strong> of {pageCount}</span>
              <button type="button" onClick={() => setPage((current) => Math.min(pageCount, current + 1))} disabled={page >= pageCount}>
                Next <ChevronRight size={16} />
              </button>
            </nav>
          </footer>
        </div>
      )}
      {target && (
        <div
          className="period-participant-modal"
          role="presentation"
          onMouseDown={(e) =>
            e.target === e.currentTarget && !saving && setTarget(null)
          }
        >
          <form
            onSubmit={exclude}
            role="dialog"
            aria-modal="true"
            aria-label="Exclude faculty from period"
          >
            <button
              type="button"
              className="close"
              onClick={() => setTarget(null)}
              aria-label="Close"
            >
              <X size={18} />
            </button>
            <span className="icon">
              <UserMinus size={23} />
            </span>
            <h3>Not Included in This Period</h3>
            <p>
              <strong>{target.full_name}</strong> will be removed from active
              assignments, monitoring, calculations, and reports for{" "}
              <strong>{selectedPeriod?.period_name}</strong>. Their account and
              historical records remain stored.
            </p>
            <label>
              Reason
              <select
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              >
                {reasons.map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Notes {reason === "other" ? "(required)" : "(optional)"}
              <textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                maxLength="1000"
                rows="4"
                placeholder="Add administrative context for this period exclusion."
              />
            </label>
            <footer>
              <button
                type="button"
                onClick={() => setTarget(null)}
                disabled={saving}
              >
                Cancel
              </button>
              <button type="submit" disabled={saving}>
                {saving ? (
                  <Loader2 size={16} className="animate-spin" />
                ) : (
                  <UserMinus size={16} />
                )}{" "}
                Confirm Exclusion
              </button>
            </footer>
          </form>
        </div>
      )}
      {editTarget && createPortal(
        <div
          className="period-participant-modal period-assignment-modal"
          role="presentation"
          onMouseDown={(e) =>
            e.target === e.currentTarget && !saving && setEditTarget(null)
          }
        >
          <form
            onSubmit={saveAssignment}
            role="dialog"
            aria-modal="true"
            aria-label="Edit period assignment"
          >
            <button
              type="button"
              className="close"
              onClick={() => setEditTarget(null)}
              aria-label="Close"
            >
              <X size={18} />
            </button>
            <div className="period-assignment-heading">
              <span className="icon">
                <Pencil size={22} />
              </span>
              <div>
                <small>Period-specific configuration</small>
                <h3>Edit Period Assignment</h3>
              </div>
            </div>
            <p>
              Changes for <strong>{editTarget.full_name}</strong> apply only to{" "}
              <strong>{selectedPeriod?.period_name}</strong>. Master account
              details and other periods remain unchanged.
            </p>
            <div className="period-assignment-current">
              <span>Master assignment</span>
              <strong>
                {editTarget.master_role === "dean"
                  ? "Dean"
                  : editTarget.master_role === "program_head"
                    ? "Program Head"
                    : "Faculty"}{" "}
                •{" "}
                {[editTarget.master_department, editTarget.master_program]
                  .filter(Boolean)
                  .join(" • ")}
              </strong>
            </div>
            <label>
              Role
              <select
                value={assignment.role}
                onChange={(e) =>
                  setAssignment((current) => ({
                    ...current,
                    role: e.target.value,
                    program_ids:
                      e.target.value === "dean"
                        ? []
                        : current.program_ids.slice(0, 1),
                    primary_program_id:
                      e.target.value === "dean"
                        ? ""
                        : current.program_ids[0] || "",
                    lead_program_ids:
                      e.target.value === "program_head"
                        ? current.program_ids.slice(0, 1)
                        : [],
                  }))
                }
              >
                <option value="teacher">Faculty</option>
                <option value="program_head">Program Head</option>
                <option value="dean">Acting Dean (Selected Period)</option>
              </select>
            </label>
            <label>
              Department
              <select
                value={assignment.department_id}
                onChange={(e) => {
                  setAssignment((current) => ({
                    ...current,
                    department_id: e.target.value,
                    program_ids: [],
                    primary_program_id: "",
                    lead_program_ids: [],
                    allow_co_head: false,
                    co_head_reason: "",
                  }));
                  setAssignmentConflicts([]);
                }}
              >
                <option value="">Select department</option>
                {options.departments.map((department) => (
                  <option key={department.id} value={department.id}>
                    {department.department_name}
                  </option>
                ))}
              </select>
            </label>
            {assignment.role === "dean" ? (
              <section className="period-acting-dean-fields">
                <strong>Acting Dean Authorization</strong>
                <small>
                  This changes authority only for the selected evaluation
                  period. The permanent account role remains unchanged.
                </small>
                <label className="period-administrative-reason">
                  <span>
                    Administrative Reason
                    <em>Required</em>
                  </span>
                  <textarea
                    rows="3"
                    maxLength="500"
                    required
                    value={assignment.acting_dean_reason}
                    onChange={(e) =>
                      setAssignment((current) => ({
                        ...current,
                        acting_dean_reason: e.target.value,
                      }))
                    }
                    placeholder="Explain why this Program Head is serving as Acting Dean."
                  />
                  <small>{assignment.acting_dean_reason.length}/500 characters</small>
                </label>
                <label className="period-previous-dean-control">
                  <span>Previous Dean Participation</span>
                  <select
                    value={assignment.replaced_dean_action}
                    onChange={(e) =>
                      setAssignment((current) => ({
                        ...current,
                        replaced_dean_action: e.target.value,
                      }))
                    }
                  >
                    <option value="faculty">Remain as Faculty Member</option>
                    <option value="no_assignments">Keep Account, No Assignments</option>
                    <option value="excluded">Exclude from This Period</option>
                  </select>
                  <small>
                    {assignment.replaced_dean_action === "faculty"
                      ? "The previous Dean remains included as a Faculty Member for this period."
                      : assignment.replaced_dean_action === "no_assignments"
                        ? "The previous Dean remains included but receives no evaluation assignments."
                        : "The previous Dean will not participate in this evaluation period."}
                  </small>
                </label>
              </section>
            ) : assignment.role === "program_head" ? (
              <>
                <fieldset className="period-program-selector">
                  <legend>
                    Assigned Programs{" "}
                    <small>Select two or more when needed</small>
                  </legend>
                  {availablePrograms.map((program) => {
                    const selected = assignment.program_ids.includes(
                      String(program.id),
                    );
                    return (
                      <label
                        key={program.id}
                        className={selected ? "selected" : ""}
                      >
                        <input
                          type="checkbox"
                          checked={selected}
                          onChange={() => toggleAssignedProgram(program.id)}
                        />
                        <span className="check">
                          {selected && <Check size={14} />}
                        </span>
                        <span>
                          <strong>{program.program_code}</strong>
                          <small>{program.program_name}</small>
                        </span>
                      </label>
                    );
                  })}
                </fieldset>
                {assignment.program_ids.length > 0 && (
                  <>
                    <label>
                      Primary Program
                      <select
                        value={assignment.primary_program_id}
                        onChange={(e) =>
                          setAssignment((current) => ({
                            ...current,
                            primary_program_id: e.target.value,
                          }))
                        }
                      >
                        {availablePrograms
                          .filter((program) =>
                            assignment.program_ids.includes(String(program.id)),
                          )
                          .map((program) => (
                            <option key={program.id} value={program.id}>
                              {program.program_code} — {program.program_name}
                            </option>
                          ))}
                      </select>
                    </label>
                    <fieldset className="period-lead-selector">
                      <legend>Lead Evaluator Responsibility</legend>
                      <small>
                        Select programs where this account generates Program
                        Head evaluations. Leave a conflicted program unchecked
                        to retain its existing lead.
                      </small>
                      {availablePrograms
                        .filter((program) =>
                          assignment.program_ids.includes(String(program.id)),
                        )
                        .map((program) => (
                          <label key={program.id}>
                            <input
                              type="checkbox"
                              checked={assignment.lead_program_ids.includes(
                                String(program.id),
                              )}
                              onChange={() =>
                                setAssignment((current) => ({
                                  ...current,
                                  lead_program_ids:
                                    current.lead_program_ids.includes(
                                      String(program.id),
                                    )
                                      ? current.lead_program_ids.filter(
                                          (id) => id !== String(program.id),
                                        )
                                      : [
                                          ...current.lead_program_ids,
                                          String(program.id),
                                        ],
                                }))
                              }
                            />
                            <span>{program.program_code}</span>
                          </label>
                        ))}
                    </fieldset>
                  </>
                )}
              </>
            ) : (
              <label>
                Program (optional)
                <select
                  value={assignment.program_ids[0] || ""}
                  onChange={(e) =>
                    setAssignment((current) => ({
                      ...current,
                      program_ids: e.target.value ? [e.target.value] : [],
                      primary_program_id: e.target.value,
                      lead_program_ids: [],
                    }))
                  }
                  disabled={!assignment.department_id}
                >
                  <option value="">No program assigned</option>
                  {availablePrograms.map((program) => (
                    <option key={program.id} value={program.id}>
                      {program.program_code} — {program.program_name}
                    </option>
                  ))}
                </select>
              </label>
            )}
            {assignmentConflicts.length > 0 && (
              <section className="period-cohead-conflict" role="alert">
                <div>
                  <ShieldAlert size={20} />
                  <span>
                    <strong>Program Head conflict</strong>
                    <small>
                      These programs already have an assigned head for this
                      period.
                    </small>
                  </span>
                </div>
                <ul>
                  {assignmentConflicts.map((conflict) => (
                    <li
                      key={`${conflict.program_id}-${conflict.existing_head_user_id}`}
                    >
                      <b>{conflict.program_code}</b>
                      <span>
                        {conflict.existing_head_name}
                        {conflict.existing_head_is_lead
                          ? " · Current lead"
                          : ""}
                      </span>
                    </li>
                  ))}
                </ul>
                <label className="period-cohead-toggle">
                  <input
                    type="checkbox"
                    checked={assignment.allow_co_head}
                    onChange={(e) =>
                      setAssignment((current) => ({
                        ...current,
                        allow_co_head: e.target.checked,
                      }))
                    }
                  />
                  <span>Allow co-head arrangement for this period</span>
                </label>
                {assignment.allow_co_head && (
                  <label>
                    Authorization Reason
                    <textarea
                      rows="3"
                      maxLength="500"
                      value={assignment.co_head_reason}
                      onChange={(e) =>
                        setAssignment((current) => ({
                          ...current,
                          co_head_reason: e.target.value,
                        }))
                      }
                      placeholder="Explain why a co-head arrangement is authorized."
                    />
                  </label>
                )}
              </section>
            )}
            <small className="period-assignment-warning">
              Open assignments involving this participant will be archived and
              regenerated for the selected period. Submitted evaluations remain
              unchanged.
            </small>
            <footer>
              <button
                type="button"
                onClick={() => setEditTarget(null)}
                disabled={saving}
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={
                  saving ||
                  (assignment.role === "dean" &&
                    !assignment.acting_dean_reason.trim())
                }
                title={
                  assignment.role === "dean" &&
                  !assignment.acting_dean_reason.trim()
                    ? "Enter the required Acting Dean administrative reason."
                    : undefined
                }
              >
                {saving ? (
                  <Loader2 size={16} className="animate-spin" />
                ) : (
                  <Save size={16} />
                )}{" "}
                Save Period Assignment
              </button>
            </footer>
          </form>
        </div>,
        document.body,
      )}
    </section>
  );
}
