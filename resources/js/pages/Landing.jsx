import React from 'react';
import Header from '../components/landing/Header';
import Hero from '../components/landing/Hero';
import Stats from '../components/landing/Stats';
import Services from '../components/landing/Services';
import Features from '../components/landing/Features';
import AiSection from '../components/landing/AiSection';
import Cta from '../components/landing/Cta';
import Footer from '../components/landing/Footer';

export default function Landing() {
    return (
        <div className="min-h-screen bg-white font-sans antialiased">
            <Header />
            <Hero />
            <Stats />
            <Services />
            <Features />
            <AiSection />
            <Cta />
            <Footer />
        </div>
    );
}
