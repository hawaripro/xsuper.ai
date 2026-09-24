import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router-dom';
import { LocaleProvider } from '../../resources/js/contexts/LocaleContext';
import { AuthProvider } from '../../resources/js/contexts/AuthContext';
import ArtifactPanel, { ArtifactPreview } from '../../resources/js/components/chat/ArtifactPanel';

const root = createRoot(document.getElementById('artifact-test'));
window.renderArtifactPreview = artifact => root.render(
    <MemoryRouter initialEntries={['/en/chat']}>
        <LocaleProvider><ArtifactPreview artifact={artifact} /></LocaleProvider>
    </MemoryRouter>,
);


function ArtifactPanelHarness({ conversationId, artifactId }) {
    const [selected, setSelected] = useState(artifactId);
    return <ArtifactPanel conversationId={conversationId} activeArtifactId={selected} onSelectArtifact={setSelected} onDirtyChange={dirty => { window.__artifactDirty = dirty; }} />;
}

window.renderArtifactPanel = props => root.render(
    <MemoryRouter initialEntries={['/en/chat']}>
        <LocaleProvider><AuthProvider><ArtifactPanelHarness {...props} /></AuthProvider></LocaleProvider>
    </MemoryRouter>,
);