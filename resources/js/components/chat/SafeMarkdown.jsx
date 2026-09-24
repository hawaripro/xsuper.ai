import React, { useEffect, useMemo, useRef, useState } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { useLocale } from '../../contexts/LocaleContext';
import ChatIcon from './ChatIcon';
import './chat-workspace-panels.css';

function safeLink(value) {
    if (typeof value !== 'string' || /[\u0000-\u0020\\]/.test(value)) return undefined;
    if (/^(https?:|mailto:)/i.test(value)) {
        try { const url = new URL(value); return url.username || url.password ? undefined : value; } catch { return undefined; }
    }
    if ((value.startsWith('/') && !value.startsWith('//')) || value.startsWith('#')) return value;
    return undefined;
}

function CodeBlock({ children, onSaveCode, sourceMessageId }) {
    const { locale } = useLocale();
    const en = locale === 'en';
    const code = React.Children.toArray(children).find(React.isValidElement);
    const content = String(code?.props.children ?? '');
    const language = /(?:^|\s)language-([^\s]+)/.exec(code?.props.className || '')?.[1] || 'text';
    // A response stopped right after an opening fence leaves nothing to save or copy.
    const empty = content.trim() === '';
    const [copied, setCopied] = useState(false);
    const [error, setError] = useState('');
    const timer = useRef(null);
    useEffect(() => () => clearTimeout(timer.current), []);
    const copy = async () => {
        try {
            if (!navigator.clipboard?.writeText) throw new Error(en ? 'Clipboard access is unavailable. Select the code to copy it.' : 'Akses clipboard tidak tersedia. Pilih kode untuk menyalinnya.');
            await navigator.clipboard.writeText(content);
            setCopied(true); setError(''); clearTimeout(timer.current);
            timer.current = setTimeout(() => setCopied(false), 1800);
        } catch (failure) { setError(failure.message || (en ? 'Could not copy the code.' : 'Kode tidak dapat disalin.')); }
    };
    const save = async () => {
        try { await onSaveCode({ content, language, sourceMessageId }); setError(''); }
        catch (failure) { setError(failure.message); }
    };
    return <div className="cwp-code-block" dir="ltr">
        <div className="cwp-code-toolbar">
            <span className="cwp-code-language">{language}</span>
            <div className="cwp-code-actions">
                {empty ? <span className="cwp-code-empty">{en ? 'Empty code block' : 'Blok kode kosong'}</span> : <>
                    {onSaveCode && <button type="button" onClick={save}><ChatIcon name="save" />{en ? 'Save artifact' : 'Simpan hasil'}</button>}
                    <button type="button" onClick={copy} aria-label={en ? 'Copy code' : 'Salin kode'}><ChatIcon name={copied ? 'check' : 'copy'} />{copied ? (en ? 'Copied' : 'Tersalin') : (en ? 'Copy' : 'Salin')}</button>
                </>}
            </div>
        </div>
        <pre tabIndex={0} aria-label={en ? `${language} code` : `Kode ${language}`}><code className={code?.props.className}>{content}</code></pre>
        <span className="cwp-sr-only" role="status">{copied ? (en ? 'Code copied.' : 'Kode tersalin.') : ''}</span>
        {error && <p className="cwp-error" role="alert">{error}</p>}
    </div>;
}

export default function SafeMarkdown({ content, onSaveCode, sourceMessageId }) {
    const components = useMemo(() => ({
        pre: ({ children }) => <CodeBlock onSaveCode={onSaveCode} sourceMessageId={sourceMessageId}>{children}</CodeBlock>,
        a: ({ href, children }) => {
            const safe = safeLink(href);
            return safe ? <a href={safe} target={safe.startsWith('#') ? undefined : '_blank'} rel="noopener noreferrer">{children}</a> : <span>{children}</span>;
        },
        img: ({ src, alt }) => {
            const safe = safeLink(src);
            if (safe?.startsWith('/api/') && !safe.startsWith('//')) return <img src={safe} alt={alt || ''} loading="lazy" />;
            return safe ? <a href={safe} target="_blank" rel="noopener noreferrer">{alt || safe}</a> : <span>{alt}</span>;
        },
        table: ({ children }) => <div className="cwp-markdown-table" tabIndex={0}><table>{children}</table></div>,
    }), [onSaveCode, sourceMessageId]);
    return <div className="cw-markdown cwp-markdown" dir="auto">
        <ReactMarkdown remarkPlugins={[remarkGfm]} components={components} skipHtml urlTransform={safeLink}>{typeof content === 'string' ? content : ''}</ReactMarkdown>
    </div>;
}
