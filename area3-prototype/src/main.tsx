import { createRoot } from 'react-dom/client';
import { Component, type ReactNode } from 'react';
import App from './App';
import './styles.css';
class ErrorBoundary extends Component<{ children: ReactNode }, { error: string }> {
  state = { error: '' };
  static getDerivedStateFromError(error: Error) { return { error: error.message }; }
  render() { return this.state.error ? <main className="error-page"><h1>Halaman belum dapat dimuat</h1><p>{this.state.error}</p><button onClick={() => location.assign('/dashboard')}>Kembali ke Dashboard</button></main> : this.props.children; }
}
createRoot(document.getElementById('root')!).render(<ErrorBoundary><App/></ErrorBoundary>);
