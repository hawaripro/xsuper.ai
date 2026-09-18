import React from 'react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router-dom';
import { LocaleProvider } from '../../resources/js/contexts/LocaleContext';
import PageErrorBoundary from '../../resources/js/components/dashboard/PageErrorBoundary';

function UnstablePage() {
    if (!sessionStorage.getItem('qa-recovery-ready')) throw new Error('private error details');
    return <h1>Recovered content</h1>;
}

sessionStorage.removeItem('qa-recovery-ready');
document.addEventListener('click', () => sessionStorage.setItem('qa-recovery-ready', 'yes'), { capture: true });
createRoot(document.getElementById('recovery-test')).render(
    <MemoryRouter initialEntries={['/en/dashboard']}>
        <LocaleProvider>
            <PageErrorBoundary><UnstablePage /></PageErrorBoundary>
        </LocaleProvider>
    </MemoryRouter>,
);
