import { Component } from 'react';

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    console.error('[ErrorBoundary]', error, info);
  }

  reset = () => {
    this.setState({ error: null });
    this.props.onReset?.();
  };

  render() {
    if (!this.state.error) return this.props.children;

    return (
      <section className="admin-box module-wide page-enter app-error-boundary">
        <div>
          <strong>{this.props.title || 'This section could not be displayed.'}</strong>
          <p>{this.props.message || 'Please reload this section. If the problem continues, check the latest form preview or questionnaire changes.'}</p>
          <small>{this.state.error?.message || 'Unexpected interface error.'}</small>
        </div>
        <button type="button" className="primary-button" onClick={this.reset}>
          Reload Section
        </button>
      </section>
    );
  }
}
