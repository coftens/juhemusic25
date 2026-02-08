using CommunityToolkit.Mvvm.Messaging.Messages;

namespace MusicLyricApp.ViewModels.Messages;

public class CloseWindowMessage(string? token = null) : ValueChangedMessage<string?>(token);