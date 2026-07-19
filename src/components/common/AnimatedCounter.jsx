import { useState, useEffect, useRef } from 'react';

/**
 * AnimatedCounter — counts from 0 to `value` when the element
 * scrolls into view.  Subsequent value changes animate smoothly
 * from the previous displayed value to the new target.
 *
 * Props:
 *  - value        : number  — the final number to count to
 *  - suffix       : string  — optional suffix (e.g. "%")
 *  - duration     : number  — animation duration in ms (default 1400)
 *  - decimals     : number  — decimal places (default 0)
 *  - as           : string  — rendered HTML tag (default "strong")
 *  - className    : string  — extra classes
 *  - formatFn     : (n: number) => string  — custom formatter
 */
export default function AnimatedCounter({
  value = 0,
  suffix = '',
  duration = 1400,
  decimals = 0,
  as: Tag = 'strong',
  className = '',
  formatFn,
}) {
  const [displayValue, setDisplayValue] = useState(0);
  const [hasAnimated, setHasAnimated] = useState(false);
  const ref = useRef(null);
  const rafRef = useRef(null);
  const lastValueRef = useRef(0);

  // ── IntersectionObserver: trigger the first animation when visible ──
  useEffect(() => {
    const node = ref.current;
    if (!node || hasAnimated) return;

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setHasAnimated(true);
        }
      },
      { threshold: 0.3 }
    );

    observer.observe(node);
    return () => observer.disconnect();
  }, [hasAnimated]);

  // ── Animate from the last displayed value to the current `value` ──
  useEffect(() => {
    if (!hasAnimated) {
      setDisplayValue(0);
      return;
    }

    // If the value hasn't changed since the last animation, do nothing
    if (lastValueRef.current === value) return;

    const startTime = performance.now();
    const startValue = lastValueRef.current;
    const delta = value - startValue;

    // Skip animation if delta is 0 or if this is just a tiny floating-point wobble
    if (Math.abs(delta) < 0.001) return;

    function step(now) {
      const elapsed = now - startTime;
      const progress = Math.min(elapsed / duration, 1);

      // Cubic bezier ease-out: t => 1 - (1 - t)^3
      const eased = 1 - Math.pow(1 - progress, 3);
      const current = startValue + delta * eased;

      setDisplayValue(current);

      if (progress < 1) {
        rafRef.current = requestAnimationFrame(step);
      } else {
        setDisplayValue(value);
        lastValueRef.current = value;
      }
    }

    rafRef.current = requestAnimationFrame(step);
    return () => {
      if (rafRef.current) cancelAnimationFrame(rafRef.current);
    };
  }, [hasAnimated, value, duration]);

  const formatted = formatFn
    ? formatFn(displayValue)
    : displayValue.toFixed(decimals);

  return (
    <Tag ref={ref} className={className} data-counted={hasAnimated ? 'true' : 'false'}>
      {formatted}{suffix}
    </Tag>
  );
}
