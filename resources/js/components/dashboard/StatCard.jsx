
export default function StatCard({ label, value, detail, tone = 'neutral', icon }) {
    return (
        <article className={`ui-stat-card ui-stat-${tone}`}>
            <div className="flex items-start justify-between gap-3">
                <p className="ui-overline">{label}</p>
                {icon && <span className="ui-stat-icon" aria-hidden="true">{icon}</span>}
            </div>
            <strong className="ui-stat-value">{value}</strong>
            {detail && <p className="ui-stat-detail">{detail}</p>}
        </article>
    );
}
