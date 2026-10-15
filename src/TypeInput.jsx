import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown } from 'lucide-react';

// An editable list: opening or typing never filters the available types.
export default function TypeInput({ id, label, value, onChange, options, required, maxLength, placeholder='输入或选择类型' }) {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [position, setPosition] = useState(null);
  const root = useRef(null), input = useRef(null), list = useRef(null), items = useRef([]);
  const listId = id + '-options';

  useLayoutEffect(() => {
    if (!open) return;
    function place() {
      const rect = root.current.getBoundingClientRect();
      const below = window.innerHeight - rect.bottom - 14;
      const above = rect.top - 14;
      const rowHeight = window.matchMedia('(max-width:680px)').matches ? 44 : 36;
      const height = Math.min(260, options.length ? options.length * rowHeight + 10 : 48);
      const upwards = below < height && above > below;
      const maxHeight = Math.max(0, Math.min(height, upwards ? above : below));
      setPosition({
        left: Math.max(8, Math.min(rect.left, window.innerWidth - rect.width - 8)),
        width: rect.width,
        maxHeight,
        ...(upwards ? { bottom: window.innerHeight - rect.top + 6 } : { top: rect.bottom + 6 }),
      });
    }
    place();
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    return () => {
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
    };
  }, [open, options.length]);

  useEffect(() => {
    if (!open) return;
    function outside(event) {
      if (!root.current.contains(event.target) && !list.current?.contains(event.target)) setOpen(false);
    }
    document.addEventListener('pointerdown', outside);
    return () => document.removeEventListener('pointerdown', outside);
  }, [open]);

  useEffect(() => {
    if (open && active >= 0) items.current[active]?.scrollIntoView({ block: 'nearest' });
  }, [open, active]);

  function show() { setActive(-1); setOpen(true); }
  function choose(option) { onChange(option); setOpen(false); input.current.focus(); }
  function keyDown(event) {
    if (event.nativeEvent.isComposing) return;
    if (event.key === 'Escape' && open) {
      event.preventDefault(); event.stopPropagation(); setOpen(false);
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault(); setOpen(true);
      setActive(previous => {
        if (!options.length) return -1;
        if (!open || previous < 0) return event.key === 'ArrowDown' ? 0 : options.length - 1;
        return (previous + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
      });
    } else if (event.key === 'Enter' && open && active >= 0) {
      event.preventDefault(); choose(options[active]);
    } else if (event.key === 'Tab' || event.key === 'Enter') setOpen(false);
  }

  return <div className="type-input" ref={root}>
    <input id={id} ref={input} value={value} required={required} maxLength={maxLength} placeholder={placeholder}
      role="combobox" aria-autocomplete="none" aria-haspopup="listbox" aria-expanded={open} aria-controls={open ? listId : undefined}
      aria-activedescendant={open && active >= 0 ? listId + '-' + active : undefined} autoComplete="off"
      onClick={show} onChange={event => { onChange(event.target.value); show(); }} onKeyDown={keyDown}
      onBlur={event => { if (!root.current.contains(event.relatedTarget) && !list.current?.contains(event.relatedTarget)) setOpen(false); }}/>
    <button type="button" className="type-input-toggle" tabIndex={-1} aria-label={(open ? '收起' : '展开') + label + '列表'}
      aria-expanded={open} aria-controls={open ? listId : undefined} onPointerDown={event => event.preventDefault()}
      onClick={() => { input.current.focus(); if (open) setOpen(false); else show(); }}><ChevronDown size={16}/></button>
    {open && position && createPortal(<div className="type-options" id={listId} ref={list} role="listbox" aria-label={label + '选项'} style={position}>
      {options.map((option, index) => <div key={option} id={listId + '-' + index} ref={el => { items.current[index] = el; }}
        role="option" aria-selected={option === value} className={'type-option' + (active === index ? ' active' : '')}
        onPointerDown={event => event.preventDefault()} onClick={() => choose(option)}>
        <span>{option}</span>{option === value && <Check size={14}/>}
      </div>)}
      {!options.length && <div className="type-options-empty">暂无类型，可直接输入</div>}
    </div>, document.body)}
  </div>;
}
