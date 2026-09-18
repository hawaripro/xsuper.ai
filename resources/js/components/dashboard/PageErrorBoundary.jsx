import { Component } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useLocale } from '../../contexts/LocaleContext';

class RenderBoundary extends Component {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error, info) {
        console.error('Page rendering failed', error, info.componentStack);
    }

    render() {
        if (!this.state.failed) return this.props.children;
        const { t, home } = this.props;
        return (
            <section className="ui-page" role="alert">
                <div className="ui-card p-6">
                    <h1 className="text-lg font-bold text-slate-900 dark:text-white">{t('Halaman tidak dapat ditampilkan')}</h1>
                    <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{t('Terjadi kesalahan pada tampilan. Coba lagi atau kembali ke dashboard.')}</p>
                    <div className="mt-5 flex flex-wrap gap-3">
                        <button type="button" className="ui-btn-primary" onClick={() => this.setState({ failed: false })}>{t('Coba lagi')}</button>
                        <Link className="ui-btn-secondary" to={home} onClick={() => this.setState({ failed: false })}>{t('Kembali ke dashboard')}</Link>
                    </div>
                </div>
            </section>
        );
    }
}

export default function PageErrorBoundary({ children }) {
    const { pathname } = useLocation();
    const { t, localizedPath } = useLocale();
    return <RenderBoundary key={pathname} t={t} home={localizedPath('/dashboard')}>{children}</RenderBoundary>;
}
