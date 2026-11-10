"use client";

import pageData from "@/data/front-page.json";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { ArrowRight, ChevronLeft, ChevronRight, Pause, Play } from "lucide-react";
import type { FrontPageSlide } from "@/config/front-page";

const content = pageData.hero;

export default function HomeHero({ slides = content.slides, heading = content.h1Text }: { slides?: (Omit<FrontPageSlide, "id"> & { id?: string })[]; heading?: string }) {
  const [active, setActive] = useState(0);
  const [paused, setPaused] = useState(false);
  const [hovered, setHovered] = useState(false);
  const [focused, setFocused] = useState(false);
  const track = useRef<HTMLDivElement>(null);

  function goTo(index: number) {
    const viewport = track.current;
    if (!viewport || !slides.length) { return; }
    const next = (index + slides.length) % slides.length;
    viewport.scrollTo({ left: next * viewport.clientWidth, behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "instant" : "smooth" });
  }

  useEffect(() => {
    if (slides.length < 2 || paused || hovered || focused) { return; }
    const timer = window.setInterval(() => {
      if (document.hidden || window.matchMedia("(prefers-reduced-motion: reduce)").matches) { return; }
      const viewport = track.current;
      if (viewport) {
        viewport.scrollTo({ left: ((active + 1) % slides.length) * viewport.clientWidth, behavior: "smooth" });
      }
    }, 6500);
    return () => window.clearInterval(timer);
  }, [active, paused, hovered, focused, slides.length]);

  useEffect(() => {
    const viewport = track.current;
    if (!viewport) { return; }
    let previousWidth = viewport.clientWidth;
    const observer = new ResizeObserver(() => {
      if (viewport.clientWidth === previousWidth) { return; }
      previousWidth = viewport.clientWidth;
      viewport.scrollTo({ left: active * viewport.clientWidth, behavior: "instant" });
    });
    observer.observe(viewport);
    return () => observer.disconnect();
  }, [active]);

  if (!slides.length) { return null; }

  return <section className="reference-hero" aria-roledescription="carousel" aria-label={content.arialabel}
    onMouseEnter={() => setHovered(true)} onMouseLeave={() => setHovered(false)}
    onFocusCapture={() => setFocused(true)} onBlurCapture={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) { setFocused(false); } }}
    onKeyDown={(event) => {
      if (event.key === "ArrowLeft" || event.key === "ArrowRight") {
        event.preventDefault(); setPaused(true); goTo(active + (event.key === "ArrowRight" ? 1 : -1));
      }
    }}>
    <h1 className="sr-only">{heading}</h1>
    <div ref={track} className="hero-slider-track" onPointerDown={() => setPaused(true)}
      onScroll={(event) => { const viewport = event.currentTarget; setActive(Math.round(viewport.scrollLeft / viewport.clientWidth)); }}>
      {slides.map((slide, index) => <div className="hero-slide" key={slide.id || slide.image + index} role="group" aria-roledescription="slide" aria-label={`${index + 1} / ${slides.length}`} aria-hidden={index !== active} inert={index !== active}>
        <img src={slide.image} alt={slide.alt} width="1774" height="887" fetchPriority={index === 0 ? "high" : "auto"} draggable={false} />
        <div className="page-container"><div className="reference-hero-copy">
          <p className="store-eyebrow">{slide.eyebrow}</p>
          <h2>{slide.title}<br /><em>{slide.accent}</em></h2>
          <p>{slide.description}</p>
          <Link className="button button-primary" href={slide.href}>{slide.action}<ArrowRight size={17} aria-hidden="true" /></Link>
        </div></div>
      </div>)}
    </div>
    <button className="reference-hero-prev" type="button" aria-label={content.arialabel2} onClick={() => { setPaused(true); goTo(active - 1); }}><ChevronLeft size={20} aria-hidden="true" /></button>
    <button className="reference-hero-next" type="button" aria-label={content.arialabel3} onClick={() => { setPaused(true); goTo(active + 1); }}><ChevronRight size={20} aria-hidden="true" /></button>
    <div className="reference-hero-dots" aria-label={content.arialabel4}>{slides.map((slide, index) => <button key={slide.id || slide.image + index} type="button" aria-label={content.accessibleLabel1.replace("{value1}", String(index + 1))} aria-pressed={index === active} onClick={() => { setPaused(true); goTo(index); }}><span /></button>)}</div>
    <button className="hero-slider-play" type="button" aria-label={paused ? content.label : content.label2} onClick={() => setPaused(!paused)}>{paused ? <Play size={15} aria-hidden="true" /> : <Pause size={15} aria-hidden="true" />}</button>
    <span className="sr-only" aria-live={paused ? "polite" : "off"} aria-atomic="true">{content.spanText}{active + 1} {content.spanText2}{slides.length}</span>
  </section>;
}
