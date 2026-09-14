
export function LoadingState({ label = 'Memuat data…' }) {
    return <div className="ui-state" role="status"><span className="ui-spinner" /> <span>{label}</span></div>;
}

export function ErrorState({ message = 'Data tidak dapat dimuat.', onRetry }) {
    return (
        <div className="ui-state ui-state-error" role="alert">
            <div><strong>Terjadi kendala.</strong><p>{message}</p></div>
            {onRetry && <button type="button" className="ui-btn-secondary" onClick={onRetry}>Coba lagi</button>}
        </div>
    );
}

export function EmptyState({ title = 'Belum ada data', description, action }) {
    return (
        <div className="ui-empty-state">
            <div className="ui-empty-mark" aria-hidden="true">◇</div>
            <strong>{title}</strong>
            {description && <p>{description}</p>}
            {action}
        </div>
    );
}
