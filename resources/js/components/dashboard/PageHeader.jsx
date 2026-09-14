
export default function PageHeader({ eyebrow, title, description, actions }) {
    return (
        <header className="ui-page-header">
            <div className="min-w-0">
                {eyebrow && <p className="ui-overline text-red-500">{eyebrow}</p>}
                <h1 className="ui-page-title">{title}</h1>
                {description && <p className="ui-page-description">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </header>
    );
}
