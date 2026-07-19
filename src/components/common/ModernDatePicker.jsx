import { useEffect, useRef, useState } from 'react';
import { DayPicker } from 'react-day-picker';
import { format } from 'date-fns';
import { CalendarDays, ChevronDown } from 'lucide-react';
import 'react-day-picker/style.css';

function parseDate(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
  if (!match) return undefined;
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  return date.getFullYear() === Number(match[1])
    && date.getMonth() === Number(match[2]) - 1
    && date.getDate() === Number(match[3]) ? date : undefined;
}

export default function ModernDatePicker({ value, onChange, onBlur, label = 'Birth date', required = false }) {
  const [open, setOpen] = useState(false);
  const [yearPickerOpen, setYearPickerOpen] = useState(false);
  const rootRef = useRef(null);
  const selected = parseDate(value);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const [displayMonth, setDisplayMonth] = useState(selected || new Date(1990, 0, 1));

  useEffect(() => {
    if (open && selected) setDisplayMonth(selected);
  }, [open, value]);

  useEffect(() => {
    if (!yearPickerOpen) return undefined;
    const frame = window.requestAnimationFrame(() => rootRef.current?.querySelector('.people-year-options .is-selected')?.scrollIntoView({ block: 'center' }));
    return () => window.cancelAnimationFrame(frame);
  }, [yearPickerOpen]);

  useEffect(() => {
    if (!open) return undefined;
    const close = (event) => {
      if (!rootRef.current?.contains(event.target)) {
        setOpen(false);
        setYearPickerOpen(false);
        onBlur?.();
      }
    };
    const escape = (event) => {
      if (event.key === 'Escape') {
        setOpen(false);
        setYearPickerOpen(false);
        rootRef.current?.querySelector('.people-date-trigger')?.focus();
        onBlur?.();
      }
    };
    document.addEventListener('pointerdown', close);
    document.addEventListener('keydown', escape);
    return () => {
      document.removeEventListener('pointerdown', close);
      document.removeEventListener('keydown', escape);
    };
  }, [open, onBlur]);

  const selectDate = (date) => {
    if (!date) return;
    onChange(format(date, 'yyyy-MM-dd'));
    setOpen(false);
    setYearPickerOpen(false);
    onBlur?.();
  };

  return (
    <div className={`people-date-picker shared-modern-date-picker ${open ? 'is-open' : ''}`} ref={rootRef}>
      <button type="button" className="people-date-trigger" aria-label={`${label}: ${selected ? format(selected, 'MMMM d, yyyy') : 'not selected'}`} aria-haspopup="dialog" aria-expanded={open} aria-required={required} onClick={(event) => { event.preventDefault(); setYearPickerOpen(false); setOpen((current) => !current); }}>
        <CalendarDays size={19} aria-hidden="true" />
        <span className={selected ? '' : 'is-placeholder'}>{selected ? format(selected, 'MMMM d, yyyy') : `Select ${label.toLowerCase()}`}</span>
        <ChevronDown size={18} aria-hidden="true" />
      </button>
      {open && (
        <div className="people-date-popover" role="dialog" aria-label={`Choose ${label.toLowerCase()}`} onClick={(event) => event.preventDefault()}>
          <div className="people-date-popover-heading"><span><CalendarDays size={17} /> {label}</span><small>{selected ? format(selected, 'EEEE, MMMM d, yyyy') : 'Choose a date below'}</small></div>
          <div className="people-date-jump" aria-label="Choose month and year">
            <select aria-label="Calendar month" value={displayMonth.getMonth()} onChange={(event) => setDisplayMonth(new Date(displayMonth.getFullYear(), Number(event.target.value), 1))}>
              {Array.from({ length: 12 }, (_, month) => <option key={month} value={month}>{format(new Date(2000, month, 1), 'MMMM')}</option>)}
            </select>
            <div className={`people-year-picker ${yearPickerOpen ? 'is-open' : ''}`}>
              <button type="button" className="people-year-trigger" aria-label={`Calendar year ${displayMonth.getFullYear()}`} aria-haspopup="listbox" aria-expanded={yearPickerOpen} onClick={() => setYearPickerOpen((current) => !current)}>{displayMonth.getFullYear()} <ChevronDown size={15} /></button>
              {yearPickerOpen && <div className="people-year-options" role="listbox" aria-label="Select year">
                {Array.from({ length: today.getFullYear() - 1939 }, (_, index) => today.getFullYear() - index).map((year) => <button type="button" role="option" aria-selected={year === displayMonth.getFullYear()} className={year === displayMonth.getFullYear() ? 'is-selected' : ''} key={year} onClick={() => { setDisplayMonth(new Date(year, displayMonth.getMonth(), 1)); setYearPickerOpen(false); }}>{year}</button>)}
              </div>}
            </div>
          </div>
          <DayPicker mode="single" selected={selected} month={displayMonth} onMonthChange={setDisplayMonth} onSelect={selectDate} disabled={{ after: today }} startMonth={new Date(1940, 0, 1)} endMonth={today} captionLayout="label" navLayout="after" showOutsideDays fixedWeeks />
          <div className="people-date-actions">
            <button type="button" onClick={() => { onChange(''); onBlur?.(); }}>Clear</button>
            <button type="button" onClick={() => selectDate(today)}>Today</button>
          </div>
        </div>
      )}
    </div>
  );
}
