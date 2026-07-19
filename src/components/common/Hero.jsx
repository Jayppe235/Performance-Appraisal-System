import { assetUrl } from '../../data/apiBase.js';

const robotImage = assetUrl('assets/images/ROBOT 1.svg');

export default function Hero({ eyebrow, title, children, actions = null, className = '', visual = null, background = null }) {
  return (
    <div className={`hero-card module-wide page-enter ${className}`.trim()}>
      {background}
      <div>
        <p className="eyebrow">{eyebrow}</p>
        <h2>{title}</h2>
        <p>{children}</p>
        {actions && <div className="hero-actions">{actions}</div>}
      </div>
      <div className="hero-illustration" aria-hidden="true">
        {visual}
        <img className="hero-robot" src={robotImage} alt="" />
      </div>
    </div>
  );
}
