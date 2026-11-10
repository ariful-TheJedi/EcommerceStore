"use client";

import { useEffect, useRef, type ComponentProps, type PointerEvent } from "react";

type ProductImageZoomProps = Pick<ComponentProps<"img">, "src" | "alt" | "width" | "height" | "loading" | "fetchPriority"> & {
  imageClassName?: string;
};

export default function ProductImageZoom({ src, alt, imageClassName = "", ...imageProps }: ProductImageZoomProps) {
  const container = useRef<HTMLSpanElement>(null);
  const frame = useRef<number | null>(null);
  const pointer = useRef({ x: 0, y: 0 });

  useEffect(() => {
    const viewport = container.current;
    if (viewport) { delete viewport.dataset.zoomed; }
    return () => {
      if (frame.current !== null) { window.cancelAnimationFrame(frame.current); frame.current = null; }
    };
  }, [src]);

  function position() {
    const viewport = container.current;
    if (!viewport) { return; }
    const bounds = viewport.getBoundingClientRect();
    if (!bounds.width || !bounds.height) { return; }
    const x = Math.max(0, Math.min(100, (pointer.current.x - bounds.left) / bounds.width * 100));
    const y = Math.max(0, Math.min(100, (pointer.current.y - bounds.top) / bounds.height * 100));
    viewport.style.setProperty("--zoom-x", `${x}%`);
    viewport.style.setProperty("--zoom-y", `${y}%`);
  }

  function enter(event: PointerEvent<HTMLSpanElement>) {
    if (event.pointerType === "touch" || !window.matchMedia("(any-hover: hover) and (any-pointer: fine)").matches) { return; }
    pointer.current = { x: event.clientX, y: event.clientY };
    position();
    event.currentTarget.dataset.zoomed = "true";
  }

  function move(event: PointerEvent<HTMLSpanElement>) {
    if (event.currentTarget.dataset.zoomed !== "true" || event.pointerType === "touch") { return; }
    pointer.current = { x: event.clientX, y: event.clientY };
    if (frame.current !== null) { return; }
    frame.current = window.requestAnimationFrame(() => { frame.current = null; position(); });
  }

  function leave() {
    if (frame.current !== null) { window.cancelAnimationFrame(frame.current); frame.current = null; }
    if (container.current) { delete container.current.dataset.zoomed; }
  }

  return <span ref={container} className="product-image-zoom" onPointerEnter={enter} onPointerMove={move} onPointerLeave={leave} onPointerCancel={leave}>
    <img {...imageProps} src={src} alt={alt} draggable={false} className={imageClassName} />
  </span>;
}
