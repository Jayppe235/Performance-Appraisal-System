import { useState, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { CheckCircle2, AlertTriangle, X, Info, Loader2 } from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Toast Context & Provider — singletons for cross-component usage    */
/* ------------------------------------------------------------------ */

let globalAddToast = null;

/**
 * addToast — importable singleton. Call it from anywhere to push a toast.
 * @param {{ type?: 'success'|'error'|'info'|'loading', text: string, duration?: number }} toast
 */
export function addToast(toast) {
    console.log('[Toast] addToast called with:', toast, '- globalAddToast exists:', !!globalAddToast);
    if (globalAddToast) {
        globalAddToast(toast);
    } else {
        console.warn('[Toast] ToastContainer not mounted yet. addToast call ignored:', toast);
    }
}

/* ------------------------------------------------------------------ */
/*  Internal helper                                                    */
/* ------------------------------------------------------------------ */

const VARIANTS = {
    success: {
        icon: CheckCircle2,
        containerClass:
            'border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-900/30',
        textClass: 'text-emerald-700 dark:text-emerald-300',
        iconClass: 'text-emerald-500 dark:text-emerald-400',
    },
    error: {
        icon: AlertTriangle,
        containerClass:
            'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/30',
        textClass: 'text-red-700 dark:text-red-300',
        iconClass: 'text-red-500 dark:text-red-400',
    },
    info: {
        icon: Info,
        containerClass:
            'border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-900/30',
        textClass: 'text-blue-700 dark:text-blue-300',
        iconClass: 'text-blue-500 dark:text-blue-400',
    },
    loading: {
        icon: Loader2,
        containerClass:
            'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800',
        textClass: 'text-gray-700 dark:text-gray-300',
        iconClass: 'text-gray-400 dark:text-gray-500',
    },
};

/* ------------------------------------------------------------------ */
/*  Single Toast Item                                                  */
/* ------------------------------------------------------------------ */

function ToastItem({ id, type, text, onRemove }) {
    const variant = VARIANTS[type] || VARIANTS.info;
    const Icon = variant.icon;

    return (
        <div
            className={`
                pointer-events-auto flex items-start gap-3 rounded-xl border
                px-4 py-3.5 shadow-lg backdrop-blur-sm
                animate-slide-in-right
                ${variant.containerClass}
            `}
            role="alert"
        >
            <Icon
                size={18}
                className={`mt-0.5 shrink-0 ${variant.iconClass} ${type === 'loading' ? 'animate-spin' : ''}`}
            />
            <p className={`flex-1 text-sm font-medium leading-5 ${variant.textClass}`}>
                {text}
            </p>
            <button
                onClick={() => onRemove(id)}
                className={`shrink-0 rounded-md p-0.5 opacity-60 transition-opacity hover:opacity-100 ${variant.textClass}`}
                aria-label="Dismiss"
            >
                <X size={14} />
            </button>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Toast Container (single instance per page)                         */
/* ------------------------------------------------------------------ */

export default function ToastContainer() {
    const [toasts, setToasts] = useState([]);

    // Expose the add function via the singleton
    const add = useCallback(({ type = 'info', text, duration = 6000 }) => {
        const id = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
        setToasts((prev) => [...prev, { id, type, text, duration }]);
        return id;
    }, []);

    useEffect(() => {
        console.log('[Toast] ToastContainer mounted - registering globalAddToast');
        globalAddToast = add;
        return () => {
            console.log('[Toast] ToastContainer unmounting - clearing globalAddToast');
            globalAddToast = null;
        };
    }, [add]);

    // Auto-dismiss — uses local timers object instead of ref to avoid Strict Mode double-invoke bug
    useEffect(() => {
        const timers = {};

        toasts.forEach(({ id, type, duration }) => {
            if (type === 'loading') return; // never auto-dismiss loading toasts
            if (!duration) return;

            timers[id] = setTimeout(() => {
                setToasts((prev) => prev.filter((t) => t.id !== id));
            }, duration);
        });

        return () => {
            Object.values(timers).forEach(clearTimeout);
        };
    }, [toasts]);

    const remove = (id) => {
        setToasts((prev) => prev.filter((t) => t.id !== id));
    };

    // Use portal to avoid ancestor CSS (transform, overflow, z-index) breaking fixed positioning
    if (toasts.length === 0) return null;

    return createPortal(
        <div
            className="fixed bottom-4 right-4 z-[9999] flex w-full max-w-sm flex-col gap-2"
            aria-live="polite"
            aria-label="Notifications"
        >
            {toasts.map((t) => (
                <ToastItem key={t.id} {...t} onRemove={remove} />
            ))}
        </div>,
        document.body
    );
}
