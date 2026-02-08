using System;
using System.Threading.Tasks;
using Avalonia.Threading;
using MsBox.Avalonia;
using NLog;

namespace MusicLyricApp.Core;

public static class DialogHelper
{
    private static readonly Logger Logger = LogManager.GetCurrentClassLogger();
    
    public static async Task ShowMessage(Exception ex, string title = "提示")
    {
        await Dispatcher.UIThread.InvokeAsync(() =>
        {
            Logger.Error(ex, "DialogHelper ShowMessage message: {ErrorMsg}, stackTrace: {StackTrace}", ex.Message, ex.StackTrace);
            var box = MessageBoxManager.GetMessageBoxStandard("提示", ex.Message);
            box.ShowWindowAsync();
        });
    }
    
    public static async Task ShowMessage(string message, string title = "提示")
    {
        await Dispatcher.UIThread.InvokeAsync(() =>
        {
            Logger.Info("DialogHelper ShowMessage message: {Message}", message);
            var box = MessageBoxManager.GetMessageBoxStandard("提示", message);
            box.ShowWindowAsync();
        });
    }
}