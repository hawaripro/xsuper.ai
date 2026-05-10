import React from 'react';
import Header from '../components/landing/Header';
import Hero from '../components/landing/Hero';
import Pricing from '../components/landing/Pricing';
import Comparison from '../components/landing/Comparison';
import Features from '../components/landing/Features';
import Audience from '../components/landing/Audience';
import Services from '../components/landing/Services';
import PaymentMethods from '../components/landing/PaymentMethods';
import Testimonials from '../components/landing/Testimonials';
import FAQ from '../components/landing/FAQ';
import Stats from '../components/landing/Stats';
import Cta from '../components/landing/Cta';
import Footer from '../components/landing/Footer';
import PurchaseNotification from '../components/landing/PurchaseNotification';

export default function Landing() {
    return (
        <div className="min-h-screen bg-white font-sans antialiased text-slate-900 overflow-x-hidden">
            <Header />
            <Hero />
            <Pricing />
            <Comparison />
            <Features />
            <Audience />
            <Services />
            <PaymentMethods />
            <Testimonials />
            <Stats />
            <FAQ />
            <Cta />
            <Footer />
            <PurchaseNotification />
        </div>
    );
}
